<?php

namespace Tests\Feature;

use App\Http\Middleware\SetLocale;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\Concerns\PipelineFixtures;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    use PipelineFixtures, RefreshDatabase;

    public function test_switching_language_translates_the_interface_and_is_remembered(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')->assertOk()->assertSee('Find leads')->assertSee('<html lang="en" dir="ltr"', false);

        $response = $this->actingAs($user)->from('/')->post('/locale/ar');
        $response->assertRedirect('/')->assertCookie(SetLocale::COOKIE, 'ar');

        $this->actingAs($user)->withCookie(SetLocale::COOKIE, 'ar')->get('/')
            ->assertOk()
            ->assertSee('<html lang="ar" dir="rtl"', false)
            ->assertSee(__('app.dashboard.submit', [], 'ar'))
            ->assertDontSee('Find leads');
    }

    public function test_an_unknown_language_is_refused_and_a_tampered_cookie_falls_back(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/locale/fr')->assertNotFound();
        $this->actingAs($user)->post('/locale/..%2Fen')->assertNotFound();

        // A cookie naming a language that is not configured must not change anything.
        $this->actingAs($user)->withCookie(SetLocale::COOKIE, '../ar')->get('/')
            ->assertOk()
            ->assertSee('<html lang="en" dir="ltr"', false);
    }

    public function test_the_how_to_use_page_works_signed_out_and_in_both_languages(): void
    {
        $this->get('/help')->assertOk()
            ->assertSee('How to use GScraper')
            ->assertSee('Re-process')
            ->assertSee('Daily budget');

        $this->withCookie(SetLocale::COOKIE, 'ar')->get('/help')->assertOk()
            ->assertSee(__('help.heading', [], 'ar'))
            ->assertSee(__('help.sections.run.title', [], 'ar'));
    }

    public function test_help_covers_every_page_in_the_navigation(): void
    {
        // The terms the guide explains are the array keys, so flatten both sides.
        $guide = Arr::dot(__('help.sections'));
        $sections = implode(' ', array_merge(array_keys($guide), array_values($guide)));

        foreach (['Find leads', 'Leads', 'Search insights', 'Import', 'Users', 'Workers'] as $page) {
            $this->assertStringContainsString($page, $sections, "The guide never mentions $page");
        }
    }

    public function test_results_from_the_worker_are_shown_in_the_chosen_language(): void
    {
        $user = User::factory()->create();
        $run = $this->completedRun($user);

        $this->actingAs($user)->withCookie(SetLocale::COOKIE, 'ar')->get("/runs/{$run->id}")
            ->assertOk()
            ->assertSee(__('data.priority.Hot', [], 'ar'))
            ->assertSee(__('data.pitch.Website redesign / UI-UX', [], 'ar'))
            ->assertSee(__('data.website_status.Has website', [], 'ar'))
            // The lead's own data stays exactly as the worker saved it.
            ->assertSee('office@acme-hvac.example');
    }

    public function test_worker_vocabularies_translate_and_unknown_values_pass_through(): void
    {
        $this->app->setLocale('ar');

        $this->assertSame('موقع إلكتروني جديد', Vocab::pitch('New website'));
        $this->assertSame('Vhorus AR/VR package', Vocab::pitch('Vhorus AR/VR package'));
        $this->assertSame('الموقع وتجربة المستخدم', Vocab::area(['key' => 'web', 'label' => 'Website & UX']));
        $this->assertSame('Something New', Vocab::area(['key' => 'unknown', 'label' => 'Something New']));
        $this->assertSame('لا يوجد موقع', Vocab::websiteStatus('No website'));
        $this->assertSame('صفحة تواصل اجتماعي فقط (Facebook)', Vocab::websiteStatus('Social page only (Facebook)'));
        $this->assertSame('هاتف فقط', Vocab::reason('PHONE_ONLY'));
        $this->assertSame('', Vocab::websiteStatus(null));
    }

    public function test_every_language_defines_the_same_keys(): void
    {
        $this->artisan('lang:check')->assertSuccessful();
    }
}
