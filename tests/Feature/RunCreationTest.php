<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Models\Run;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class RunCreationTest extends TestCase
{
    use RefreshDatabase;

    private function submit(array $data, ?User $user = null)
    {
        return $this->actingAs($user ?? User::factory()->create())
            ->post('/runs', $data + ['limit' => 20, 'months' => 11]);
    }

    public function test_dashboard_shows_the_search_form_and_worker_status(): void
    {
        $this->actingAs(User::factory()->create())->get('/')
            ->assertOk()
            ->assertSee('New search')
            ->assertSee('Offline');
    }

    public function test_searches_are_parsed_and_queued(): void
    {
        $user = User::factory()->create();

        $response = $this->submit([
            'queries' => "dentists in Austin TX\n# a comment\n\n  - plumbers in Round Rock TX \nDENTISTS in austin tx",
            'limit' => 30,
            'months' => 6,
            'no_socials' => '1',
        ], $user);

        $run = Run::query()->sole();
        $response->assertRedirect("/runs/{$run->id}");
        $this->assertSame(RunType::Scrape, $run->type);
        $this->assertSame(RunStatus::Queued, $run->status);
        $this->assertSame(['dentists in Austin TX', 'plumbers in Round Rock TX'], $run->queries);
        $this->assertSame(['limit' => 30, 'months' => 6, 'noSocials' => true, 'allowUnverified' => false, 'phoneIsChannel' => false], $run->options);
        $this->assertSame($user->id, $run->user_id);
    }

    public function test_phone_can_be_turned_into_a_usable_channel_per_run(): void
    {
        $this->submit(['queries' => 'dentists in Cairo', 'phone_is_channel' => '1']);

        $this->assertTrue(Run::query()->sole()->options['phoneIsChannel']);
    }

    public function test_searches_can_come_from_a_txt_upload(): void
    {
        $file = UploadedFile::fake()->createWithContent('searches.txt', "\xEF\xBB\xBFhair salons in Austin TX\nbarbers in Austin TX\n");

        $this->submit(['queries' => 'dentists in Austin TX', 'queries_file' => $file])->assertSessionHasNoErrors();

        $this->assertSame(['dentists in Austin TX', 'hair salons in Austin TX', 'barbers in Austin TX'], Run::query()->sole()->queries);
    }

    public function test_non_text_uploads_are_rejected(): void
    {
        $php = UploadedFile::fake()->createWithContent('searches.php', "<?php system('id');");
        // Fake uploads guess the MIME type from the name; production uses fileinfo on the
        // content, so a PNG renamed to .txt is detected as image/png.
        $png = UploadedFile::fake()->image('searches.txt')->mimeType('image/png');

        $this->submit(['queries_file' => $php])->assertSessionHasErrors('queries_file');
        $this->submit(['queries_file' => $png])->assertSessionHasErrors('queries_file');
        $this->assertSame(0, Run::query()->count());
    }

    public function test_oversized_uploads_are_rejected(): void
    {
        $big = UploadedFile::fake()->createWithContent('searches.txt', str_repeat("dentists in Austin TX\n", 4000));

        $this->submit(['queries_file' => $big])->assertSessionHasErrors('queries_file');
    }

    public function test_at_least_one_search_is_required(): void
    {
        $this->submit(['queries' => "   \n# only a comment"])->assertSessionHasErrors('queries');
        $this->assertSame(0, Run::query()->count());
    }

    public function test_search_count_and_options_are_limited(): void
    {
        config(['gscraper.max_queries_per_run' => 3]);

        $this->submit(['queries' => "a\nb\nc\nd"])->assertSessionHasErrors('queries');
        $this->submit(['queries' => 'a', 'limit' => 0])->assertSessionHasErrors('limit');
        $this->submit(['queries' => 'a', 'limit' => 5000])->assertSessionHasErrors('limit');
        $this->submit(['queries' => 'a', 'months' => 99])->assertSessionHasErrors('months');
        $this->assertSame(0, Run::query()->count());
    }
}
