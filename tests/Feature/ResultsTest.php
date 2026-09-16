<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PipelineFixtures;
use Tests\TestCase;

class ResultsTest extends TestCase
{
    use PipelineFixtures, RefreshDatabase;

    public function test_run_page_shows_leads_and_the_other_tabs(): void
    {
        $user = User::factory()->create();
        $run = $this->completedRun($user);

        $this->actingAs($user)->get("/runs/{$run->id}")
            ->assertOk()
            ->assertSee('Acme Heating &amp; Cooling', false)
            ->assertSee('office@acme-hvac.example')
            ->assertSee('Website is not mobile-friendly')
            ->assertSee('Contactability by search');

        $this->get("/runs/{$run->id}?tab=unreachable")->assertOk()->assertSee('PHONE_ONLY')->assertDontSee('office@acme-hvac.example');
        $this->get("/runs/{$run->id}?tab=inactive")->assertOk()->assertSee('Closed Furnace Co');
        $this->get("/runs/{$run->id}?tier=B")->assertOk()->assertSee('No leads match these filters.');
        $this->get("/runs/{$run->id}?q=acme")->assertOk()->assertSee('office@acme-hvac.example');
    }

    public function test_active_runs_show_the_live_progress_panel(): void
    {
        $user = User::factory()->create();
        $run = $this->makeRun($user);

        $this->actingAs($user)->get("/runs/{$run->id}")->assertOk()->assertSee('data-run-live', false)->assertSee('Waiting for a worker');
    }

    public function test_scraped_content_is_escaped_and_unsafe_links_are_not_rendered(): void
    {
        $user = User::factory()->create();
        $run = $this->completedRun($user, [], [
            'name' => '<script>alert("x")</script>Evil Co',
            'website' => 'javascript:alert(1)',
            'mapsUrl' => 'data:text/html,<b>hi</b>',
        ]);

        $html = $this->actingAs($user)->get("/runs/{$run->id}")->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert("x")</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringNotContainsString('href="data:', $html);
    }

    public function test_csv_export_neutralises_spreadsheet_formulas(): void
    {
        $user = User::factory()->create();
        $run = $this->completedRun($user, [], ['name' => '=HYPERLINK("http://evil.example","click")']);

        $response = $this->actingAs($user)->get("/runs/{$run->id}/export/leads");
        $csv = $response->streamedContent();

        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=utf-8');
        $this->assertStringStartsWith("\xEF\xBB\xBFpriority,score,contact_tier", $csv);
        $this->assertStringContainsString("\"'=HYPERLINK(", $csv);
        $this->assertStringContainsString('office@acme-hvac.example', $csv);

        $this->get("/runs/{$run->id}/export/unreachable")->assertOk();
        $this->get("/runs/{$run->id}/export/inactive")->assertOk();
        $this->get("/runs/{$run->id}/export/secrets")->assertNotFound();
    }

    public function test_leads_page_shows_each_business_once_by_default(): void
    {
        $user = User::factory()->create();
        $key = '0xabc:0xdef';
        $this->completedRun($user, ['scoring' => ['score' => 40, 'pitch' => [], 'breakdown' => [], 'reasons' => []], 'priority' => 'Warm'], ['placeKey' => $key]);
        $this->completedRun($user, [], ['placeKey' => $key]);

        $this->assertSame(1, substr_count($this->actingAs($user)->get('/leads')->assertOk()->getContent(), 'office@acme-hvac.example'));
        $this->assertSame(2, substr_count($this->get('/leads?all=1')->assertOk()->getContent(), 'office@acme-hvac.example'));
        $this->get('/leads?tier=C')->assertOk()->assertSee('No leads match.');
        $this->get('/leads/export')->assertOk();
    }

    public function test_insights_aggregate_contactability_by_search(): void
    {
        $user = User::factory()->create();
        $this->completedRun($user);

        $this->actingAs($user)->get('/insights')
            ->assertOk()
            ->assertSee('hvac in chicago')
            ->assertSee('1/2 (50%)');
    }

    public function test_security_headers_are_sent(): void
    {
        $this->get('/login')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        $this->assertStringContainsString("script-src 'self'", $this->get('/login')->headers->get('Content-Security-Policy'));
    }

    public function test_missing_pages_use_the_friendly_error_page(): void
    {
        $this->actingAs(User::factory()->create())->get('/runs/999999')->assertNotFound()->assertSee('Page not found');
    }
}
