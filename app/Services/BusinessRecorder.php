<?php

namespace App\Services;

use App\Enums\Stage;
use App\Models\Run;
use App\Models\RunBusiness;
use App\Models\RunPlace;
use App\Support\PlaceKey;
use Illuminate\Support\Str;

/**
 * Stores pipeline output (from the worker or a legacy import) and keeps the
 * run's denormalised counters in sync. Writes are upserts keyed on
 * (run_id, place_key), so a worker retrying a chunk never creates duplicates.
 */
class BusinessRecorder
{
    /** Largest accepted `detail` document per business. */
    public const MAX_DETAIL_BYTES = 512_000;

    private const TIERS = ['A', 'B', 'C', 'D'];

    private const PRIORITIES = ['Hot', 'Warm', 'Cold'];

    /**
     * @param  array<int, array{query: string, place: array}>  $items
     */
    public function recordPlaces(Run $run, array $items): int
    {
        $now = now()->toDateTimeString();
        $rows = [];
        foreach ($items as $item) {
            $place = $item['place'];
            $key = PlaceKey::normalize($place['placeKey'] ?? null, $place['mapsUrl'] ?? ($place['name'] ?? null));
            $rows[$key] = [
                'run_id' => $run->id,
                'query' => Str::limit((string) $item['query'], 250, ''),
                'place_key' => $key,
                'name' => Str::limit((string) ($place['name'] ?? ''), 250, ''),
                'data' => json_encode($place, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                'created_at' => $now,
            ];
        }
        if ($rows) {
            RunPlace::query()->upsert(array_values($rows), ['run_id', 'place_key'], ['query', 'name', 'data']);
        }

        return count($rows);
    }

    /**
     * @param  array<int, array>  $items  pipeline records: {stage, query, place, activity, site?, channels?, contact?, scoring?, priority?, websiteStatus?}
     */
    public function recordBusinesses(Run $run, array $items): int
    {
        $rows = [];
        foreach ($items as $item) {
            $row = $this->columns($run, $item);
            $rows[$row['place_key']] = $row;
        }
        if ($rows) {
            $update = array_values(array_diff(array_keys(reset($rows)), ['run_id', 'place_key', 'created_at']));
            RunBusiness::query()->upsert(array_values($rows), ['run_id', 'place_key'], $update);
        }

        return count($rows);
    }

    /**
     * Maps one pipeline record onto the run_businesses columns.
     */
    public function columns(Run $run, array $item): array
    {
        $stage = Stage::from($item['stage']);
        $place = (array) ($item['place'] ?? []);
        // Keep the detail document bounded: review texts are only needed for scoring.
        if (isset($place['reviewTexts']) && is_array($place['reviewTexts'])) {
            $place['reviewTexts'] = array_slice($place['reviewTexts'], 0, 20);
            $item['place'] = $place;
        }

        $detail = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($detail === false || strlen($detail) > self::MAX_DETAIL_BYTES) {
            throw new \InvalidArgumentException('Business record for "'.Str::limit((string) ($place['name'] ?? '?'), 60).'" is too large.');
        }

        $tier = data_get($item, 'contact.tier');
        $priority = $item['priority'] ?? $item['tier'] ?? null; // very old reports used "tier" for priority
        $score = data_get($item, 'scoring.score');
        $rating = is_numeric($place['rating'] ?? null) ? max(0, min(5, (float) $place['rating'])) : null;
        $now = now()->toDateTimeString();

        return [
            'run_id' => $run->id,
            'stage' => $stage->value,
            'query' => Str::limit((string) ($item['query'] ?? ''), 250, ''),
            'place_key' => PlaceKey::normalize($place['placeKey'] ?? null, $place['mapsUrl'] ?? ($place['name'] ?? null)),
            'name' => Str::limit((string) ($place['name'] ?? '(unnamed)'), 250, ''),
            'category' => $this->str($place['category'] ?? null, 150),
            'phone' => $this->str($place['phone'] ?? null, 50),
            'address' => $this->str($place['address'] ?? null, 500),
            'website' => $this->str($place['website'] ?? null, 2048),
            'maps_url' => $this->str($place['mapsUrl'] ?? null, 2048),
            'website_status' => $stage === Stage::Inactive ? null : $this->str($item['websiteStatus'] ?? null, 150),
            'contact_tier' => $stage === Stage::Inactive ? null : (in_array($tier, self::TIERS, true) ? $tier : null),
            'reason_code' => match ($stage) {
                Stage::Inactive => 'INACTIVE',
                default => $this->str(data_get($item, 'contact.reasonCode'), 40),
            },
            'email' => $stage === Stage::Lead ? $this->str(data_get($item, 'channels.emails.0.value'), 254) : null,
            'score' => $stage === Stage::Lead && is_numeric($score) ? max(0, min(100, (int) $score)) : null,
            'priority' => $stage === Stage::Lead && in_array($priority, self::PRIORITIES, true) ? $priority : null,
            'rating' => $rating,
            'review_count' => max(0, (int) ($place['reviewCount'] ?? 0)),
            'newest_review' => $this->str(data_get($item, 'activity.newestReview'), 60),
            'pitch' => $stage === Stage::Lead ? json_encode(array_values(array_filter((array) data_get($item, 'scoring.pitch', []), 'is_string'))) : null,
            'detail' => $detail,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Recomputes counters and the per-search contactability table from stored rows.
     */
    public function refreshCounts(Run $run): void
    {
        $groups = RunBusiness::query()
            ->where('run_id', $run->id)
            ->selectRaw('query, stage, contact_tier, priority, COUNT(*) AS n')
            ->groupBy('query', 'stage', 'contact_tier', 'priority')
            ->get();

        $empty = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'inactive' => 0];
        $overall = $empty;
        $byQuery = array_fill_keys($run->queries ?? [], $empty);
        $counts = ['leads' => 0, 'hot' => 0, 'warm' => 0, 'unreachable' => 0, 'inactive' => 0];

        foreach ($groups as $g) {
            $n = (int) $g->n;
            $byQuery[$g->query] ??= $empty;
            if ($g->stage === Stage::Inactive) {
                $overall['inactive'] += $n;
                $byQuery[$g->query]['inactive'] += $n;
                $counts['inactive'] += $n;

                continue;
            }
            $tier = $g->contact_tier ?? ($g->stage === Stage::Unreachable ? 'D' : null);
            if ($tier) {
                $overall[$tier] += $n;
                $byQuery[$g->query][$tier] += $n;
            }
            if ($g->stage === Stage::Unreachable) {
                $counts['unreachable'] += $n;
            } else {
                $counts['leads'] += $n;
                $counts['hot'] += $g->priority === 'Hot' ? $n : 0;
                $counts['warm'] += $g->priority === 'Warm' ? $n : 0;
            }
        }

        $placesCount = RunPlace::query()->where('run_id', $run->id)->count();

        $run->forceFill([
            'places_count' => $placesCount,
            'leads_count' => $counts['leads'],
            'hot_count' => $counts['hot'],
            'warm_count' => $counts['warm'],
            'unreachable_count' => $counts['unreachable'],
            'inactive_count' => $counts['inactive'],
            'tier_a_count' => $overall['A'],
            'tier_b_count' => $overall['B'],
            'tier_c_count' => $overall['C'],
            'meta' => array_merge($run->meta ?? [], [
                'contactDistribution' => ['overall' => $overall, 'byQuery' => $byQuery],
            ]),
        ])->save();
    }

    private function str(mixed $value, int $max): ?string
    {
        if (! is_scalar($value) || $value === '') {
            return null;
        }

        return Str::limit((string) $value, $max - 3);
    }
}
