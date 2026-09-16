<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Models\Run;
use App\Models\Worker;
use App\Services\BusinessRecorder;
use App\Services\ScrapeBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PipelineFixtures;
use Tests\TestCase;

/**
 * Google rate-limits by IP address, so the daily budget belongs to the internet
 * connection, not to one PC. The server enforces it.
 */
class ScrapeBudgetTest extends TestCase
{
    use PipelineFixtures, RefreshDatabase;

    private function scrapedPlaces(Worker $worker, int $count, RunType $type = RunType::Scrape, ?string $at = null): Run
    {
        $run = $this->makeRun(null, ['worker_id' => $worker->id, 'type' => $type, 'status' => RunStatus::Completed]);
        app(BusinessRecorder::class)->recordPlaces($run, array_map(
            fn () => ['query' => 'hvac in cairo', 'place' => $this->place()],
            range(1, $count)
        ));
        if ($at) {
            $run->places()->update(['created_at' => $at]);
        }

        return $run;
    }

    public function test_workers_on_one_connection_share_the_budget(): void
    {
        config(['gscraper.scrape.daily_per_connection' => 100]);
        [$a] = $this->makeWorker('PC A');
        [$b] = $this->makeWorker('PC B');
        [$far] = $this->makeWorker('Other office');
        $a->forceFill(['last_ip' => '41.0.0.7'])->save();
        $b->forceFill(['last_ip' => '41.0.0.7'])->save();
        $far->forceFill(['last_ip' => '156.0.0.9'])->save();

        $this->scrapedPlaces($a, 30);
        $this->scrapedPlaces($b, 25);
        $this->scrapedPlaces($far, 40);

        $shared = app(ScrapeBudget::class)->forWorker($a);
        $this->assertSame(55, $shared['used']);
        $this->assertSame(45, $shared['remaining']);
        $this->assertSame(2, $shared['workers']);
        $this->assertSame(['PC A', 'PC B'], $shared['workerNames']);
        $this->assertTrue($shared['canScrape']);

        // A different connection keeps its own budget.
        $this->assertSame(40, app(ScrapeBudget::class)->forWorker($far)['used']);
    }

    public function test_reprocessing_and_old_scrapes_do_not_use_the_budget(): void
    {
        [$worker] = $this->makeWorker();
        $worker->forceFill(['last_ip' => '41.0.0.7'])->save();

        $this->scrapedPlaces($worker, 10, RunType::Reprocess);
        $this->scrapedPlaces($worker, 10, RunType::Scrape, now()->subHours(25)->toDateTimeString());
        $this->scrapedPlaces($worker, 5);

        $this->assertSame(5, app(ScrapeBudget::class)->forWorker($worker)['used']);
    }

    public function test_an_exhausted_connection_only_receives_reprocess_jobs(): void
    {
        config(['gscraper.scrape.daily_per_connection' => 20, 'gscraper.scrape.min_run' => 10]);
        [$first, $firstToken] = $this->makeWorker('PC A');
        [, $secondToken] = $this->makeWorker('PC B');
        // Both PCs sit behind the address the test requests come from.
        $first->forceFill(['last_ip' => '127.0.0.1'])->save();
        $this->scrapedPlaces($first, 15); // 5 left, below min_run

        $search = $this->makeRun();
        $reprocess = $this->makeRun(null, ['type' => RunType::Reprocess]);

        // Both workers report their own limits are fine, but the connection's are not.
        $response = $this->withToken($secondToken)->postJson('/api/worker/v1/jobs/claim', ['canScrape' => true])->assertOk();
        $response->assertJsonPath('job.id', $reprocess->id)
            ->assertJsonPath('connection.canScrape', false)
            ->assertJsonPath('connection.used', 15)
            ->assertJsonPath('connection.remaining', 5);

        $this->withToken($firstToken)->postJson('/api/worker/v1/jobs/claim', ['canScrape' => true])
            ->assertOk()
            ->assertJsonPath('job', null);

        $this->assertSame(RunStatus::Queued, $search->refresh()->status);
    }

    public function test_the_job_payload_caps_how_much_a_run_may_scrape(): void
    {
        config(['gscraper.scrape.daily_per_connection' => 100, 'gscraper.scrape.per_run' => 40]);
        [$worker, $token] = $this->makeWorker();
        $this->scrapedPlaces($worker, 70);
        $this->makeRun();

        $this->withToken($token)->postJson('/api/worker/v1/jobs/claim', ['canScrape' => true])
            ->assertOk()
            ->assertJsonPath('job.maxBusinesses', 30); // 30 left today, under the 40 per-run cap
    }

    public function test_heartbeat_reports_the_connection_budget_and_when_it_frees_up(): void
    {
        config(['gscraper.scrape.daily_per_connection' => 10, 'gscraper.scrape.min_run' => 5]);
        [$worker, $token] = $this->makeWorker();
        $this->scrapedPlaces($worker, 10, RunType::Scrape, now()->subHours(4)->toDateTimeString());

        $response = $this->withToken($token)->postJson('/api/worker/v1/heartbeat')->assertOk();

        $response->assertJsonPath('connection.used', 10)
            ->assertJsonPath('connection.remaining', 0)
            ->assertJsonPath('connection.canScrape', false);
        // Slots free up 24h after each place was scraped.
        $this->assertEqualsWithDelta(
            now()->addHours(20)->getTimestampMs(),
            $response->json('connection.nextSlotAt'),
            60_000,
        );
    }

    public function test_the_dashboard_shows_the_shared_budget(): void
    {
        config(['gscraper.scrape.daily_per_connection' => 300]);
        [$worker] = $this->makeWorker('PC A');
        $worker->forceFill(['last_ip' => '41.0.0.7', 'last_seen_at' => now()])->save();
        $this->scrapedPlaces($worker, 12);

        $this->actingAs(\App\Models\User::factory()->create())->get('/')
            ->assertOk()
            ->assertSee('Daily budget')
            ->assertSee('12 / 300')
            ->assertSee('288 businesses left in the last 24 hours');
    }
}
