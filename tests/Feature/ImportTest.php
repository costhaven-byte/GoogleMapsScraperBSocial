<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Models\Run;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\PipelineFixtures;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use PipelineFixtures, RefreshDatabase;

    private function leadsJson(): UploadedFile
    {
        $strip = fn (array $item) => array_diff_key($item, ['stage' => true]);

        return UploadedFile::fake()->createWithContent('leads.json', json_encode([
            'meta' => ['queries' => ['hvac in chicago'], 'generatedAt' => '2026-09-13T12:29:33.650Z', 'maxReviewAgeMonths' => 11],
            'leads' => [$strip($this->leadItem())],
            'unreachable' => [$strip($this->unreachableItem())],
            'excluded' => [$strip($this->inactiveItem()), $strip($this->inactiveItem())],
        ]));
    }

    public function test_a_gmscraper_report_can_be_imported_and_then_reprocessed(): void
    {
        $user = User::factory()->create();
        $raw = UploadedFile::fake()->createWithContent('raw-places.jsonl', implode("\n", [
            json_encode(['query' => 'hvac in chicago', 'place' => $this->place()]),
            'not json',
            json_encode(['query' => 'hvac in chicago', 'place' => $this->place()]),
        ]))->mimeType('text/plain'); // what fileinfo reports for JSON Lines content

        $this->actingAs($user)->post('/import', ['leads_file' => $this->leadsJson(), 'raw_file' => $raw])->assertSessionHasNoErrors();

        $run = Run::query()->sole();
        $this->assertSame(RunType::Import, $run->type);
        $this->assertSame(RunStatus::Completed, $run->status);
        $this->assertSame([1, 1, 2, 2], [$run->leads_count, $run->unreachable_count, $run->inactive_count, $run->places_count]);
        $this->assertSame('leads.json', $run->meta['importedFrom']);

        $this->post("/runs/{$run->id}/reprocess")->assertRedirect();
        $copy = Run::query()->where('type', RunType::Reprocess->value)->sole();
        $this->assertSame(RunStatus::Queued, $copy->status);
        $this->assertSame($run->id, $copy->source_run_id);
        $this->assertSame(2, $copy->places()->count());
        $this->assertSame(2, $copy->places_count);
    }

    public function test_files_that_are_not_gmscraper_reports_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/import', ['leads_file' => UploadedFile::fake()->createWithContent('leads.json', '{"hello": "world"}')])
            ->assertSessionHasErrors('leads_file');
        $this->post('/import', ['leads_file' => UploadedFile::fake()->createWithContent('leads.json', 'garbage')])
            ->assertSessionHasErrors('leads_file');
        $this->post('/import', ['leads_file' => UploadedFile::fake()->createWithContent('leads.html', '<html></html>')])
            ->assertSessionHasErrors('leads_file');
        $this->post('/import', ['leads_file' => UploadedFile::fake()->image('leads.json')])
            ->assertSessionHasErrors('leads_file');

        $this->assertSame(0, Run::query()->count());
    }
}
