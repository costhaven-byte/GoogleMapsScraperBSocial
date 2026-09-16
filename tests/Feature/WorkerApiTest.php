<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Models\Run;
use App\Models\RunBusiness;
use App\Models\RunPlace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PipelineFixtures;
use Tests\TestCase;

class WorkerApiTest extends TestCase
{
    use PipelineFixtures, RefreshDatabase;

    private function api(string $token)
    {
        return $this->withToken($token)->withHeader('Accept', 'application/json');
    }

    /** @return array{0: Run, 1: string} a run claimed by a fresh worker, and that worker's token */
    private function claimedRun(array $attributes = []): array
    {
        [, $token] = $this->makeWorker();
        $this->makeRun(User::factory()->create(), $attributes);
        $id = $this->api($token)->postJson('/api/worker/v1/jobs/claim', ['canScrape' => true])->json('job.id');

        return [Run::query()->findOrFail($id), $token];
    }

    public function test_requests_without_a_valid_token_are_rejected(): void
    {
        [$worker, $token] = $this->makeWorker();

        $this->postJson('/api/worker/v1/heartbeat')->assertUnauthorized();
        $this->api('gsw_'.str_repeat('x', 48))->postJson('/api/worker/v1/heartbeat')->assertUnauthorized();
        $this->api('not-a-token')->postJson('/api/worker/v1/heartbeat')->assertUnauthorized();

        $worker->forceFill(['revoked_at' => now()])->save();
        $this->api($token)->postJson('/api/worker/v1/heartbeat')->assertUnauthorized();
    }

    public function test_heartbeat_records_worker_details_and_whitelisted_usage(): void
    {
        [$worker, $token] = $this->makeWorker();

        $this->api($token)->postJson('/api/worker/v1/heartbeat', [
            'version' => '1.0.0',
            'hostname' => 'OFFICE-PC',
            'usage' => ['used' => 12, 'limit' => 300, 'canStart' => true, 'evil' => '<script>'],
        ])->assertOk()->assertJsonStructure(['serverTime', 'pollSeconds']);

        $worker->refresh();
        $this->assertTrue($worker->isOnline());
        $this->assertSame('OFFICE-PC', $worker->hostname);
        $this->assertSame(['used' => 12, 'limit' => 300, 'canStart' => true], $worker->usage);
        $this->assertSame(64, strlen($worker->token_hash));
        $this->assertStringNotContainsString($token, $worker->token_hash);
    }

    public function test_claim_returns_no_job_when_nothing_is_queued(): void
    {
        [, $token] = $this->makeWorker();

        $this->api($token)->postJson('/api/worker/v1/jobs/claim', ['canScrape' => true])
            ->assertOk()
            ->assertJsonPath('job', null)
            ->assertJsonPath('connection.canScrape', true);
    }

    public function test_jobs_are_claimed_oldest_first_and_only_once(): void
    {
        [$workerA, $tokenA] = $this->makeWorker('A');
        [, $tokenB] = $this->makeWorker('B');
        $first = $this->makeRun();
        $second = $this->makeRun();

        $this->api($tokenA)->postJson('/api/worker/v1/jobs/claim', ['canScrape' => true])
            ->assertOk()
            ->assertJsonPath('job.id', $first->id)
            ->assertJsonPath('job.type', 'scrape')
            ->assertJsonPath('job.queries', ['hvac in chicago'])
            ->assertJsonPath('job.options.limit', 20);
        $this->api($tokenB)->postJson('/api/worker/v1/jobs/claim', ['canScrape' => true])->assertJsonPath('job.id', $second->id);
        $this->api($tokenA)->postJson('/api/worker/v1/jobs/claim', ['canScrape' => true])->assertOk()->assertJsonPath('job', null);

        $this->assertSame(RunStatus::Claimed, $first->refresh()->status);
        $this->assertSame($workerA->id, $first->worker_id);
    }

    public function test_a_rate_limited_worker_only_receives_reprocess_jobs(): void
    {
        [, $token] = $this->makeWorker();
        $this->makeRun();
        $reprocess = $this->makeRun(null, ['type' => RunType::Reprocess]);

        $this->api($token)->postJson('/api/worker/v1/jobs/claim', ['canScrape' => false])->assertJsonPath('job.id', $reprocess->id);
        $this->api($token)->postJson('/api/worker/v1/jobs/claim', ['canScrape' => false])->assertOk()->assertJsonPath('job', null);
    }

    public function test_logs_mark_the_run_running_and_report_cancellation(): void
    {
        [$run, $token] = $this->claimedRun();

        $this->api($token)->postJson("/api/worker/v1/jobs/{$run->id}/logs", [
            'lines' => [['message' => 'Searching: "hvac in chicago"'], ['message' => "bad\x07char", 'level' => 'warn']],
            'progress' => ['phase' => 'Searching Google Maps (1/1)', 'pct' => 12, 'active' => 3, 'tiers' => ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0]],
        ])->assertOk()->assertJson(['cancelRequested' => false]);

        $run->refresh();
        $this->assertSame(RunStatus::Running, $run->status);
        $this->assertSame(3, $run->progress['active']);
        $this->assertSame('badchar', $run->logs()->orderByDesc('id')->value('message'));

        $run->forceFill(['cancel_requested' => true])->save();
        $this->api($token)->postJson("/api/worker/v1/jobs/{$run->id}/logs", ['lines' => []])->assertJson(['cancelRequested' => true]);

        // The browser's progress poll sees the lines after a given id.
        $firstLogId = $run->logs()->min('id');
        $this->actingAs(User::factory()->create())->getJson("/runs/{$run->id}/progress?after={$firstLogId}")
            ->assertOk()
            ->assertJsonPath('status', 'running')
            ->assertJsonCount(1, 'logs')
            ->assertJsonPath('progress.phase', 'Searching Google Maps (1/1)');
    }

    public function test_place_uploads_are_idempotent_and_can_be_paged_back(): void
    {
        [$run, $token] = $this->claimedRun();
        $place = $this->place();
        $payload = ['places' => [['query' => 'hvac in chicago', 'place' => $place], ['query' => 'hvac in chicago', 'place' => $this->place()]]];

        $this->api($token)->postJson("/api/worker/v1/jobs/{$run->id}/places", $payload)->assertOk()->assertJson(['stored' => 2]);
        $this->api($token)->postJson("/api/worker/v1/jobs/{$run->id}/places", $payload)->assertOk();

        $this->assertSame(2, RunPlace::query()->where('run_id', $run->id)->count());
        $this->assertSame(2, $run->refresh()->places_count);

        $page = $this->api($token)->getJson("/api/worker/v1/jobs/{$run->id}/places?after_id=0&limit=1")->assertOk();
        $page->assertJsonCount(1, 'places')->assertJsonPath('places.0.place.name', 'Acme Heating & Cooling');
        $next = $page->json('nextAfterId');
        $this->api($token)->getJson("/api/worker/v1/jobs/{$run->id}/places?after_id={$next}&limit=1")->assertJsonCount(1, 'places');
    }

    public function test_results_are_stored_with_searchable_columns_and_full_detail(): void
    {
        [$run, $token] = $this->claimedRun();
        $lead = $this->leadItem();

        $this->api($token)->postJson("/api/worker/v1/jobs/{$run->id}/results", [
            'items' => [$lead, $this->unreachableItem(), $this->inactiveItem()],
        ])->assertOk()->assertJson(['stored' => 3]);
        // Retrying the same chunk must not duplicate rows.
        $this->api($token)->postJson("/api/worker/v1/jobs/{$run->id}/results", ['items' => [$lead]])->assertOk();

        $this->assertSame(3, RunBusiness::query()->count());
        $b = RunBusiness::query()->where('stage', 'lead')->sole();
        $this->assertSame('A', $b->contact_tier);
        $this->assertSame('office@acme-hvac.example', $b->email);
        $this->assertSame(62, $b->score);
        $this->assertSame('Hot', $b->priority);
        $this->assertSame(['Website redesign / UI-UX', 'Meta awareness & lead-gen campaign'], $b->pitch);
        $this->assertSame('Wix', $b->d('site.builder'));
        $this->assertSame('PHONE_ONLY', RunBusiness::query()->where('stage', 'unreachable')->value('reason_code'));
    }

    public function test_invalid_or_oversized_results_are_rejected(): void
    {
        [$run, $token] = $this->claimedRun();

        $this->api($token)->postJson("/api/worker/v1/jobs/{$run->id}/results", ['items' => [['stage' => 'hacked', 'place' => ['name' => 'x']]]])
            ->assertUnprocessable();

        $huge = $this->leadItem(['site' => ['kind' => ['type' => 'own-site'], 'junk' => str_repeat('x', 600_000)]]);
        $this->api($token)->postJson("/api/worker/v1/jobs/{$run->id}/results", ['items' => [$huge]])->assertUnprocessable();
        $this->assertSame(0, RunBusiness::query()->count());
    }

    public function test_complete_computes_counts_and_the_contactability_table(): void
    {
        [$run, $token] = $this->claimedRun();
        $this->api($token)->postJson("/api/worker/v1/jobs/{$run->id}/results", [
            'items' => [$this->leadItem(), $this->leadItem(['priority' => 'Warm', 'contact' => ['tier' => 'C', 'reasonCode' => 'FACEBOOK_MESSENGER_ONLY']]), $this->unreachableItem(), $this->inactiveItem()],
        ]);

        $this->api($token)->postJson("/api/worker/v1/jobs/{$run->id}/complete", [
            'status' => 'completed',
            'meta' => ['enrichmentStats' => ['fetched' => 10], 'limitHit' => true, 'ignored' => 'x'],
        ])->assertOk();

        $run->refresh();
        $this->assertSame(RunStatus::Completed, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertSame([2, 1, 1, 1, 1, 0, 1], [$run->leads_count, $run->hot_count, $run->warm_count, $run->unreachable_count, $run->tier_a_count, $run->tier_b_count, $run->tier_c_count]);
        $this->assertSame(1, $run->inactive_count);
        $this->assertSame(['A' => 1, 'B' => 0, 'C' => 1, 'D' => 1, 'inactive' => 1], $run->meta['contactDistribution']['overall']);
        $this->assertTrue($run->meta['limitHit']);
        $this->assertArrayNotHasKey('ignored', $run->meta);
    }

    public function test_workers_cannot_touch_jobs_they_do_not_own_or_that_ended(): void
    {
        [$run, $token] = $this->claimedRun();
        [, $otherToken] = $this->makeWorker('Other');

        $this->api($otherToken)->postJson("/api/worker/v1/jobs/{$run->id}/logs", ['lines' => []])->assertNotFound();

        $run->forceFill(['status' => RunStatus::Failed])->save();
        $this->api($token)->postJson("/api/worker/v1/jobs/{$run->id}/logs", ['lines' => []])->assertStatus(409);
    }

    public function test_runs_whose_worker_went_silent_are_marked_failed(): void
    {
        [$run] = $this->claimedRun();
        [$fresh] = $this->claimedRun();
        $run->forceFill(['last_activity_at' => now()->subMinutes(30)])->save();

        $this->artisan('runs:fail-stale')->assertSuccessful();

        $this->assertSame(RunStatus::Failed, $run->refresh()->status);
        $this->assertStringContainsString('stopped responding', $run->error);
        $this->assertSame(RunStatus::Claimed, $fresh->refresh()->status);
    }
}
