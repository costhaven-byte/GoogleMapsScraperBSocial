<?php

namespace App\Services;

use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Enums\Stage;
use App\Models\Run;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Imports a finished run from a GMSCraper output folder: leads.json (required)
 * and raw-places.jsonl (optional; enables Re-process). Files are parsed from
 * PHP's temp upload location and never stored.
 */
class LegacyImporter
{
    public const MAX_BUSINESSES = 5000;

    public function __construct(private BusinessRecorder $recorder) {}

    public function import(User $user, UploadedFile $leadsJson, ?UploadedFile $rawPlaces): Run
    {
        $data = json_decode((string) file_get_contents($leadsJson->getRealPath()), true, 64);
        if (! is_array($data) || ! is_array($data['leads'] ?? null) || ! is_array($data['excluded'] ?? null)) {
            throw ValidationException::withMessages(['leads_file' => 'This is not a GMSCraper leads.json file.']);
        }

        $items = [];
        foreach (['leads' => Stage::Lead, 'unreachable' => Stage::Unreachable, 'excluded' => Stage::Inactive] as $key => $stage) {
            foreach ((array) ($data[$key] ?? []) as $entry) {
                if (is_array($entry) && is_array($entry['place'] ?? null) && isset($entry['place']['name'])) {
                    $items[] = ['stage' => $stage->value, 'query' => (string) ($entry['query'] ?? '')] + $entry;
                }
            }
        }
        if (count($items) > self::MAX_BUSINESSES) {
            throw ValidationException::withMessages(['leads_file' => 'This report has more than '.self::MAX_BUSINESSES.' businesses.']);
        }

        $meta = (array) ($data['meta'] ?? []);
        $queries = array_values(array_unique(array_filter(array_map(
            fn ($q) => is_string($q) ? mb_substr(trim($q), 0, 200) : null,
            (array) ($meta['queries'] ?? [])
        ))));
        if (! $queries) {
            $queries = array_values(array_unique(array_filter(array_column($items, 'query'))));
        }

        return DB::transaction(function () use ($user, $items, $meta, $queries, $leadsJson, $rawPlaces) {
            $run = new Run;
            $run->forceFill([
                'user_id' => $user->id,
                'type' => RunType::Import,
                'status' => RunStatus::Completed,
                'queries' => array_slice($queries, 0, 50),
                'options' => ['months' => (int) ($meta['maxReviewAgeMonths'] ?? 11)],
                'meta' => array_filter([
                    'importedFrom' => mb_substr($leadsJson->getClientOriginalName(), 0, 120),
                    'generatedAt' => is_string($meta['generatedAt'] ?? null) ? $meta['generatedAt'] : null,
                    'maxReviewAgeMonths' => $meta['maxReviewAgeMonths'] ?? null,
                    'enrichmentStats' => is_array($meta['enrichmentStats'] ?? null) ? $meta['enrichmentStats'] : null,
                ], fn ($v) => $v !== null),
                'finished_at' => now(),
            ])->save();

            foreach (array_chunk($items, 200) as $chunk) {
                try {
                    $this->recorder->recordBusinesses($run, $chunk);
                } catch (\InvalidArgumentException $e) {
                    throw ValidationException::withMessages(['leads_file' => $e->getMessage()]);
                }
            }

            if ($rawPlaces) {
                $this->importPlaces($run, $rawPlaces);
            }

            $this->recorder->refreshCounts($run);

            return $run;
        });
    }

    private function importPlaces(Run $run, UploadedFile $file): void
    {
        $handle = fopen($file->getRealPath(), 'r');
        $batch = [];
        $total = 0;
        while (($line = fgets($handle)) !== false) {
            $row = json_decode(trim($line), true, 32);
            if (! is_array($row) || ! is_array($row['place'] ?? null) || ! isset($row['place']['name'])) {
                continue;
            }
            $batch[] = ['query' => (string) ($row['query'] ?? ''), 'place' => $row['place']];
            if (++$total > self::MAX_BUSINESSES) {
                break;
            }
            if (count($batch) === 200) {
                $this->recorder->recordPlaces($run, $batch);
                $batch = [];
            }
        }
        fclose($handle);
        if ($batch) {
            $this->recorder->recordPlaces($run, $batch);
        }
    }
}
