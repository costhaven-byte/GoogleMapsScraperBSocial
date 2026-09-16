<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Models\Run;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PipelineFixtures;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use PipelineFixtures, RefreshDatabase;

    public function test_members_cannot_open_admin_pages(): void
    {
        $member = User::factory()->create();
        [$worker] = $this->makeWorker();

        $this->actingAs($member);
        $this->get('/admin/users')->assertForbidden();
        $this->get('/admin/users/create')->assertForbidden();
        $this->get('/admin/workers')->assertForbidden();
        $this->get('/admin/workers/setup')->assertForbidden();
        $this->post('/admin/workers', ['name' => 'Sneaky'])->assertForbidden();
        $this->delete("/admin/workers/{$worker->id}")->assertForbidden();
        $this->put("/admin/users/{$member->id}", ['name' => 'x', 'email' => $member->email, 'role' => 'admin', 'is_active' => 1])->assertForbidden();

        $this->assertFalse($member->refresh()->isAdmin());
    }

    public function test_admins_can_open_admin_pages(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/admin/users')->assertOk();
        $this->get('/admin/workers')->assertOk();
        $this->get('/admin/workers/setup')->assertOk()->assertSee('SERVER_URL=');
    }

    public function test_members_see_shared_runs_but_cannot_delete_other_peoples_runs(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $run = $this->completedRun($owner);

        $this->actingAs($other)->get("/runs/{$run->id}")->assertOk();
        $this->actingAs($other)->delete("/runs/{$run->id}")->assertForbidden();
        $this->assertModelExists($run);

        $this->actingAs($owner)->delete("/runs/{$run->id}")->assertRedirect('/runs');
        $this->assertModelMissing($run);
    }

    public function test_admin_can_delete_any_finished_run(): void
    {
        $run = $this->completedRun(User::factory()->create());

        $this->actingAs(User::factory()->admin()->create())->delete("/runs/{$run->id}");

        $this->assertModelMissing($run);
        $this->assertDatabaseCount('run_businesses', 0);
    }

    public function test_active_runs_cannot_be_deleted(): void
    {
        $owner = User::factory()->create();
        $run = $this->makeRun($owner, ['status' => RunStatus::Running]);

        $this->actingAs($owner)->delete("/runs/{$run->id}")->assertForbidden();
        $this->assertModelExists($run);
    }

    public function test_only_the_owner_or_an_admin_can_stop_a_run(): void
    {
        $owner = User::factory()->create();
        $queued = $this->makeRun($owner);
        $running = $this->makeRun($owner, ['status' => RunStatus::Running]);

        $this->actingAs(User::factory()->create())->post("/runs/{$queued->id}/cancel")->assertForbidden();

        $this->actingAs($owner)->post("/runs/{$queued->id}/cancel");
        $this->assertSame(RunStatus::Cancelled, $queued->refresh()->status);

        $this->actingAs(User::factory()->admin()->create())->post("/runs/{$running->id}/cancel");
        $this->assertSame(RunStatus::Running, $running->refresh()->status);
        $this->assertTrue($running->cancel_requested);
    }

    public function test_reprocess_needs_a_finished_run_with_saved_places(): void
    {
        $user = User::factory()->create();
        $empty = $this->makeRun($user, ['status' => RunStatus::Completed]);
        $active = $this->makeRun($user, ['status' => RunStatus::Running, 'places_count' => 5]);

        $this->actingAs($user)->post("/runs/{$empty->id}/reprocess")->assertForbidden();
        $this->actingAs($user)->post("/runs/{$active->id}/reprocess")->assertForbidden();
        $this->assertSame(2, Run::query()->count());
    }

    public function test_role_and_status_are_not_mass_assignable(): void
    {
        $input = ['name' => 'X', 'email' => 'x@example.com', 'password' => 'secret-123456', 'role' => 'admin', 'is_active' => false];

        // Outside production, strict mode turns the attempt into an exception...
        try {
            new User($input);
            $this->fail('Mass-assigning role should throw outside production.');
        } catch (MassAssignmentException) {
        }

        // ...and in production the fields are silently dropped.
        Model::preventSilentlyDiscardingAttributes(false);
        try {
            $user = new User($input);
        } finally {
            Model::preventSilentlyDiscardingAttributes(true);
        }
        $this->assertArrayNotHasKey('role', $user->getAttributes());
        $this->assertArrayNotHasKey('is_active', $user->getAttributes());
    }
}
