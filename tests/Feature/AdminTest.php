<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\RunLog;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\PipelineFixtures;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use PipelineFixtures, RefreshDatabase;

    public function test_admin_can_create_a_user(): void
    {
        $this->actingAs(User::factory()->admin()->create())->post('/admin/users', [
            'name' => 'Sara',
            'email' => 'sara@example.com',
            'role' => 'member',
            'password' => 'good-password-1',
            'password_confirmation' => 'good-password-1',
        ])->assertRedirect('/admin/users');

        $user = User::query()->where('email', 'sara@example.com')->sole();
        $this->assertSame(UserRole::Member, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('good-password-1', $user->password));
    }

    public function test_the_last_active_admin_cannot_be_demoted_or_deactivated(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put("/admin/users/{$admin->id}", [
            'name' => $admin->name, 'email' => $admin->email, 'role' => 'member', 'is_active' => 1,
        ])->assertSessionHasErrors('role');

        $this->assertTrue($admin->refresh()->isAdmin());
    }

    public function test_admin_can_deactivate_a_member(): void
    {
        $member = User::factory()->create();

        $this->actingAs(User::factory()->admin()->create())->put("/admin/users/{$member->id}", [
            'name' => $member->name, 'email' => $member->email, 'role' => 'member', 'is_active' => 0,
        ])->assertRedirect('/admin/users');

        $this->assertFalse($member->refresh()->is_active);
    }

    public function test_worker_token_is_shown_once_stored_hashed_and_can_be_revoked(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post('/admin/workers', ['name' => 'Office PC']);
        $token = session('worker_token');
        $response->assertRedirect('/admin/workers');
        $this->assertMatchesRegularExpression('/^gsw_[A-Za-z0-9]{48}$/', $token);

        $this->get('/admin/workers')->assertSee($token);
        $this->get('/admin/workers')->assertDontSee($token);

        $worker = Worker::query()->sole();
        $this->assertSame(hash('sha256', $token), $worker->token_hash);
        $this->withToken($token)->postJson('/api/worker/v1/heartbeat')->assertOk();

        $this->delete("/admin/workers/{$worker->id}");
        $this->withToken($token)->postJson('/api/worker/v1/heartbeat')->assertUnauthorized();
    }

    public function test_create_admin_command(): void
    {
        $this->artisan('app:create-admin', ['email' => 'owner@example.com', '--name' => 'Owner'])
            ->expectsQuestion('Password (min 10 characters, letters and numbers)', 'owner-pass-123')
            ->expectsQuestion('Repeat password', 'owner-pass-123')
            ->assertSuccessful();

        $this->assertTrue(User::query()->where('email', 'owner@example.com')->sole()->isAdmin());
    }

    public function test_prune_logs_command_deletes_only_old_lines(): void
    {
        $run = $this->makeRun();
        RunLog::query()->insert([
            ['run_id' => $run->id, 'level' => 'info', 'message' => 'old', 'created_at' => now()->subDays(60)],
            ['run_id' => $run->id, 'level' => 'info', 'message' => 'new', 'created_at' => now()],
        ]);

        $this->artisan('runs:prune-logs')->assertSuccessful();

        $this->assertSame(['new'], RunLog::query()->pluck('message')->all());
    }

    public function test_scheduler_has_the_maintenance_tasks(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('runs:fail-stale')
            ->expectsOutputToContain('runs:prune-logs')
            ->assertSuccessful();
    }
}
