<x-layouts.app :title="$run->title()">
    @php
        $dist = data_get($run->meta, 'contactDistribution');
        $byQuery = $dist['byQuery'] ?? [];
        $tabUrl = fn (string $t, array $extra = []) => route('runs.show', ['run' => $run, 'tab' => $t] + $extra);
        $withFilter = fn (array $change) => route('runs.show', array_filter(array_merge(
            ['run' => $run->id, 'tab' => 'leads', 'tier' => $filters['tier'], 'priority' => $filters['priority'], 'q' => $filters['search'] ?: null, 'sort' => $filters['sort'] !== 'score' ? $filters['sort'] : null],
            $change
        ), fn ($v) => $v !== null && $v !== ''));
    @endphp

    {{-- Header --}}
    <div class="mb-5 flex flex-wrap items-start gap-3">
        <div class="min-w-0 flex-1">
            <h1 class="text-xl font-bold tracking-tight">{{ $run->title() }}</h1>
            <p class="muted mt-1 text-[13px]">
                {{ $run->type->label() }} #{{ $run->id }}
                · {{ __('app.runs.created', ['time' => local_time($run->created_at)]) }}{{ $run->user ? ' '.__('app.runs.by_user', ['name' => $run->user->name]) : '' }}
                @if ($run->worker) · {{ __('app.runs.worker', ['name' => $run->worker->name]) }} @endif
                @if ($run->sourceRun) · <a class="link" href="{{ route('runs.show', $run->sourceRun) }}">{{ __('app.runs.from_run', ['id' => $run->sourceRun->id]) }}</a> @endif
                @if (data_get($run->meta, 'importedFrom')) · {{ __('app.runs.imported_from', ['source' => data_get($run->meta, 'importedFrom')]) }} @endif
                · {{ __('app.runs.active_definition', ['months' => data_get($run->options, 'months', data_get($run->meta, 'maxReviewAgeMonths', 11))]) }}
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if (! $run->isActive())
                @if ($run->leads_count)<a class="btn" href="{{ route('runs.export', [$run, 'leads']) }}">{{ __('app.runs.export_leads') }}</a>@endif
                @if ($run->unreachable_count)<a class="btn" href="{{ route('runs.export', [$run, 'unreachable']) }}">{{ __('app.runs.export_unreachable') }}</a>@endif
                @if ($run->inactive_count)<a class="btn" href="{{ route('runs.export', [$run, 'inactive']) }}">{{ __('app.runs.export_inactive') }}</a>@endif
            @endif
            @can('reprocess', $run)
                <form method="POST" action="{{ route('runs.reprocess', $run) }}" data-confirm="{{ __('app.runs.reprocess_confirm', ['count' => $run->places_count]) }}">
                    @csrf
                    <button class="btn" title="{{ __('app.runs.reprocess_title') }}">{{ __('app.runs.reprocess') }}</button>
                </form>
            @endcan
            @can('cancel', $run)
                <form method="POST" action="{{ route('runs.cancel', $run) }}" data-confirm="{{ __('app.runs.stop_confirm') }}">
                    @csrf
                    <button class="btn btn-danger" @disabled($run->cancel_requested)>{{ $run->cancel_requested ? __('app.runs.stopping') : __('app.runs.stop') }}</button>
                </form>
            @endcan
            @can('delete', $run)
                <form method="POST" action="{{ route('runs.destroy', $run) }}" data-confirm="{{ __('app.runs.delete_confirm') }}">
                    @csrf
                    @method('DELETE')
                    <button class="btn btn-danger">{{ __('app.runs.delete') }}</button>
                </form>
            @endcan
        </div>
    </div>

    {{-- Live progress while a worker is on it --}}
    @if ($run->isActive())
        @php($p = $run->progress ?? [])
        <section class="card mb-6 grid gap-3 p-5" data-run-live data-progress-url="{{ route('runs.progress', $run) }}" data-after="{{ $logs->last()?->id ?? 0 }}" aria-live="polite">
            <div class="flex items-center gap-3">
                <span class="badge badge-{{ $run->status->value }}" data-field="status">{{ $run->status->label() }}</span>
                <span class="muted" data-field="phase">{{ $p['phase'] ?? ($run->status->value === 'queued' ? __('app.runs.waiting_for_worker') : __('app.runs.starting')) }}</span>
            </div>
            <div class="meter h-2 max-w-3xl"><i data-field="bar" style="width: {{ max(2, min(100, (float) ($p['pct'] ?? 2))) }}%"></i></div>
            <div class="flex flex-wrap gap-3">
                <x-stat :value="$p['active'] ?? 0" :label="__('app.runs.stat.active')" field="active" />
                <x-stat :value="$p['excluded'] ?? 0" :label="__('app.runs.stat.excluded')" field="excluded" />
                <div class="card min-w-[110px] px-4 py-2.5">
                    <b class="block text-[22px] tabular-nums" data-field="reachable">{{ ($p['tiers']['A'] ?? 0) + ($p['tiers']['B'] ?? 0) + ($p['tiers']['C'] ?? 0) }}</b>
                    <span class="muted text-xs">{{ __('app.runs.stat.reachable') }} · <span data-field="tiers" dir="ltr">A {{ $p['tiers']['A'] ?? 0 }} · B {{ $p['tiers']['B'] ?? 0 }} · C {{ $p['tiers']['C'] ?? 0 }}</span></span>
                </div>
                <x-stat :value="$p['tiers']['D'] ?? 0" :label="__('app.runs.stat.unreachable_d')" field="unreachable" />
                <x-stat :value="$p['hot'] ?? 0" :label="__('app.runs.stat.hot')" field="hot" />
            </div>
            @if ($run->status->value === 'queued')
                <p class="muted text-[13px]">{{ __('app.runs.queued_note') }}</p>
            @endif
            <div class="note note-bad" data-worker-offline hidden>{{ __('app.runs.worker_offline_note', ['minutes' => config('gscraper.worker_stale_minutes')]) }}</div>
            <div class="note" data-cancel-requested @if (! $run->cancel_requested) hidden @endif>{{ __('app.runs.cancel_requested_note') }}</div>
            <div class="note note-bad" data-blocked hidden><b>{{ __('app.runs.blocked_note_strong') }}</b> {{ __('app.runs.blocked_note') }}</div>
            <div class="note" data-limit-hit hidden>{{ __('app.runs.limit_note') }}</div>
            <pre class="log" dir="ltr" data-log>@foreach ($logs as $log)[{{ local_time($log->created_at, 'g:i:s A') }}] {{ $log->message }}
@endforeach</pre>
        </section>
    @else
        @if ($run->status->value === 'failed')
            <div class="note note-bad mb-5"><b>{{ __('app.runs.failed_strong') }}</b> {{ $run->error }} @if ($run->places_count) {{ __('app.runs.failed_places', ['count' => $run->places_count]) }} @endif</div>
        @elseif ($run->status->value === 'cancelled')
            <div class="note mb-5"><b>{{ __('app.runs.cancelled_strong') }}</b> @if ($run->places_count) {{ __('app.runs.cancelled_places', ['count' => $run->places_count]) }} @endif</div>
        @endif
        @if (data_get($run->meta, 'blocked'))
            <div class="note note-bad mb-5"><b>{{ __('app.runs.blocked_after_strong') }}</b> {{ __('app.runs.blocked_after') }}</div>
        @endif
        @if (data_get($run->meta, 'limitHit'))
            <div class="note mb-5">{{ __('app.runs.limit_after') }}</div>
        @endif

        {{-- Summary --}}
        <div class="mb-5 flex flex-wrap gap-3">
            <x-stat :value="$run->leads_count" :label="__('app.runs.stat.leads')" />
            <x-stat :value="$run->tier_a_count" :label="__('app.runs.stat.tier_a')" />
            <x-stat :value="$run->tier_b_count" :label="__('app.runs.stat.tier_b')" />
            <x-stat :value="$run->tier_c_count" :label="__('app.runs.stat.tier_c')" />
            <x-stat :value="$run->unreachable_count" :label="__('app.runs.stat.tier_d')" />
            <x-stat :value="$run->inactive_count" :label="__('app.runs.stat.inactive')" />
            <x-stat :value="$run->hot_count" :label="__('app.runs.stat.hot_short')" />
        </div>

        @if ($dist)
            <div class="table-wrap mb-6">
                <table class="table min-w-[640px]">
                    <thead><tr><th>{{ __('app.runs.contactability_by_search') }}</th><th>{{ __('app.runs.th.inactive') }}</th><th>A</th><th>B</th><th>C</th><th>D</th><th>{{ __('app.runs.mix') }}</th><th>{{ __('app.runs.th.reachable') }}</th></tr></thead>
                    <tbody>
                        @foreach (array_merge([__('app.runs.all_searches') => $dist['overall']], count($byQuery) > 1 ? $byQuery : []) as $label => $t)
                            @php($total = $t['A'] + $t['B'] + $t['C'] + $t['D'])
                            <tr @class(['font-semibold' => $loop->first])>
                                <td>{{ $label }}</td><td>{{ $t['inactive'] }}</td><td>{{ $t['A'] }}</td><td>{{ $t['B'] }}</td><td>{{ $t['C'] }}</td><td>{{ $t['D'] }}</td>
                                <td><x-tier-stack :t="$t" /></td>
                                <td dir="ltr">{{ $total ? ($total - $t['D']).'/'.$total.' ('.round(($total - $t['D']) / $total * 100).'%)' : __('app.common.dash') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Tabs --}}
        <nav class="mb-3 flex flex-wrap gap-2" aria-label="{{ __('app.runs.result_type') }}">
            <a class="chip" href="{{ $tabUrl('leads') }}" aria-current="{{ $tab === 'leads' ? 'true' : 'false' }}">{{ __('app.runs.tab_leads', ['count' => $run->leads_count]) }}</a>
            <a class="chip" href="{{ $tabUrl('unreachable') }}" aria-current="{{ $tab === 'unreachable' ? 'true' : 'false' }}">{{ __('app.runs.tab_unreachable', ['count' => $run->unreachable_count]) }}</a>
            <a class="chip" href="{{ $tabUrl('inactive') }}" aria-current="{{ $tab === 'inactive' ? 'true' : 'false' }}">{{ __('app.runs.tab_inactive', ['count' => $run->inactive_count]) }}</a>
        </nav>

        @if ($tab === 'leads')
            <div class="mb-3 flex flex-wrap items-center gap-2">
                <form method="GET" action="{{ route('runs.show', $run) }}" class="flex gap-2">
                    <input type="hidden" name="tab" value="leads">
                    @foreach (['tier', 'priority'] as $keep)
                        @if ($filters[$keep])<input type="hidden" name="{{ $keep }}" value="{{ $filters[$keep] }}">@endif
                    @endforeach
                    <input class="input w-64" type="search" name="q" value="{{ $filters['search'] }}" placeholder="{{ __('app.runs.filter_placeholder') }}" maxlength="100">
                    <select class="input w-auto" name="sort" aria-label="{{ __('app.runs.sort_by') }}">
                        @foreach (['score', 'name', 'reviews', 'rating'] as $value)
                            <option value="{{ $value }}" @selected($filters['sort'] === $value)>{{ __('app.runs.sort', ['field' => __('app.runs.sort_'.$value)]) }}</option>
                        @endforeach
                    </select>
                    <button class="btn">{{ __('app.common.apply') }}</button>
                </form>
                <span class="mx-1 h-5 w-px bg-line"></span>
                <a class="chip" href="{{ $withFilter(['tier' => null]) }}" aria-current="{{ $filters['tier'] ? 'false' : 'true' }}">{{ __('app.runs.all_tiers') }}</a>
                @foreach (['A' => __('app.runs.tier_a_short'), 'B' => __('app.runs.tier_b_short'), 'C' => __('app.runs.tier_c_short')] as $value => $label)
                    <a class="chip" href="{{ $withFilter(['tier' => $value]) }}" aria-current="{{ $filters['tier'] === $value ? 'true' : 'false' }}">{{ $label }}</a>
                @endforeach
                <span class="mx-1 h-5 w-px bg-line"></span>
                <a class="chip" href="{{ $withFilter(['priority' => null]) }}" aria-current="{{ $filters['priority'] ? 'false' : 'true' }}">{{ __('app.runs.any_priority') }}</a>
                @foreach (['Hot', 'Warm', 'Cold'] as $value)
                    <a class="chip" href="{{ $withFilter(['priority' => $value]) }}" aria-current="{{ $filters['priority'] === $value ? 'true' : 'false' }}">{{ \App\Support\Vocab::priority($value) }}</a>
                @endforeach
            </div>

            <div class="table-wrap">
                <table class="table min-w-[980px]">
                    <thead><tr><th>{{ __('app.runs.th.score') }}</th><th>{{ __('app.runs.th.contact') }}</th><th>{{ __('app.runs.th.business') }}</th><th>{{ __('app.runs.th.website') }}</th><th>{{ __('app.runs.th.problems') }}</th><th>{{ __('app.runs.th.pitch') }}</th><th>{{ __('app.runs.th.reviews') }}</th><th>{{ __('app.runs.th.last_review') }}</th></tr></thead>
                    <tbody>
                        @forelse ($businesses as $b)
                            @include('runs._lead-row', ['b' => $b])
                        @empty
                            <tr><td colspan="8" class="muted p-8 text-center">{{ __('app.runs.no_leads_match') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="muted mt-2 text-xs">{{ __('app.runs.row_hint') }}</p>
        @elseif ($tab === 'unreachable')
            <p class="muted mb-3">{{ __('app.runs.unreachable_intro') }}</p>
            <div class="table-wrap">
                <table class="table min-w-[900px]">
                    <thead><tr><th>{{ __('app.runs.th.business') }}</th><th>{{ __('app.runs.th.reason_code') }}</th><th>{{ __('app.runs.th.why') }}</th><th>{{ __('app.runs.th.website') }}</th><th>{{ __('app.runs.th.sources_checked') }}</th></tr></thead>
                    <tbody>
                        @forelse ($businesses as $b)
                            <tr>
                                <td><x-ext-link :url="$b->maps_url" :label="$b->name" /><div class="muted text-xs">{{ $b->category }}</div></td>
                                <td><span class="code" dir="ltr">{{ $b->reason_code }}</span><div class="muted mt-0.5 text-xs">{{ \App\Support\Vocab::reason($b->reason_code) }}</div></td>
                                <td>{{ $b->d('contact.why') }}</td>
                                <td>@if ($b->website)<x-ext-link :url="$b->website" :label="\App\Support\Vocab::websiteStatus($b->website_status)" />@else{{ \App\Support\Vocab::websiteStatus($b->website_status) }}@endif</td>
                                <td class="muted text-xs">@foreach (\App\Services\RunRows::sources($b) as $source){{ $source }}<br>@endforeach</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="muted p-8 text-center">{{ __('app.runs.unreachable_empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @else
            <p class="muted mb-3">{{ __('app.runs.inactive_intro') }}</p>
            <div class="table-wrap">
                <table class="table min-w-[760px]">
                    <thead><tr><th>{{ __('app.runs.th.business') }}</th><th>{{ __('app.runs.th.why_excluded') }}</th><th>{{ __('app.runs.th.status_on_maps') }}</th><th>{{ __('app.runs.th.last_review') }}</th></tr></thead>
                    <tbody>
                        @forelse ($businesses as $b)
                            <tr>
                                <td><x-ext-link :url="$b->maps_url" :label="$b->name" /><div class="muted text-xs">{{ $b->category }}</div></td>
                                <td>@foreach ((array) $b->d('activity.reasons', []) as $reason){{ $reason }}<br>@endforeach</td>
                                <td>{{ $b->d('place.businessStatus') }}</td>
                                <td class="muted">{{ $b->newest_review }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="muted p-8 text-center">{{ __('app.runs.inactive_empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
        {{ $businesses->links() }}

        @if ($logs->isNotEmpty())
            <details class="mt-8">
                <summary class="muted cursor-pointer text-[13px]">{{ __('app.runs.worker_log', ['count' => $logs->count()]) }}</summary>
                <pre class="log mt-2" dir="ltr">@foreach ($logs as $log)[{{ local_time($log->created_at, 'g:i:s A') }}] {{ $log->message }}
@endforeach</pre>
            </details>
        @endif
    @endif
</x-layouts.app>
