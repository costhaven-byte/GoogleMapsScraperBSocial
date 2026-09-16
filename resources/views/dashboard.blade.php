<x-layouts.app :title="__('app.dashboard.title')">
    @php
        $runBudget = collect($connections)->max('runBudget');
        $workerReason = $workerStatus['reason'] ?? null;
        $workerLocked = $workerStatus && empty($workerStatus['canStart']) && in_array($workerReason, ['cooldown', 'blocked', 'daily'], true);
    @endphp

    <div class="grid gap-6 lg:grid-cols-[380px_1fr]">
        <div class="grid content-start gap-6">
            <section class="card" aria-labelledby="new-search">
                <form method="POST" action="{{ route('runs.store') }}" enctype="multipart/form-data" class="grid gap-3.5 p-5"
                      data-search-form data-run-budget="{{ $runBudget }}">
                    @csrf
                    <div class="flex items-baseline justify-between gap-2">
                        <h2 id="new-search" class="text-base font-bold">{{ __('app.dashboard.new_search') }}</h2>
                        <a class="link text-xs" href="{{ route('help') }}#search">{{ __('app.nav.help') }}</a>
                    </div>
                    <label class="label">{{ __('app.dashboard.searches') }} <span class="font-normal">{{ __('app.dashboard.searches_hint') }}</span>
                        <textarea class="input min-h-[110px] resize-y" name="queries" maxlength="20000" placeholder="{{ __('app.dashboard.searches_placeholder') }}">{{ old('queries') }}</textarea>
                    </label>
                    <label class="label">{{ __('app.dashboard.upload') }} <span class="font-normal">{{ __('app.dashboard.upload_hint') }}</span>
                        <input class="input" type="file" name="queries_file" accept=".txt,text/plain">
                    </label>
                    <div class="grid grid-cols-2 gap-2.5">
                        <label class="label">{{ __('app.dashboard.results_per_search') }}
                            <input class="input" type="number" name="limit" min="1" max="{{ $maxResults }}" value="{{ old('limit', 20) }}" required>
                        </label>
                        <label class="label">{{ __('app.dashboard.months') }}
                            <input class="input" type="number" name="months" min="1" max="60" value="{{ old('months', 11) }}" required>
                        </label>
                    </div>
                    <div class="grid gap-1.5 text-[13px]">
                        <label class="flex items-center gap-2"><input type="checkbox" name="phone_is_channel" value="1" @checked(old('phone_is_channel', $phoneIsChannel))> {{ __('app.dashboard.phone_is_channel') }}</label>
                        <p class="muted -mt-1 text-xs">{{ __('app.dashboard.phone_is_channel_help') }}</p>
                        <label class="flex items-center gap-2"><input type="checkbox" name="no_socials" value="1" @checked(old('no_socials'))> {{ __('app.dashboard.no_socials') }}</label>
                        <label class="flex items-center gap-2"><input type="checkbox" name="allow_unverified" value="1" @checked(old('allow_unverified'))> {{ __('app.dashboard.allow_unverified') }}</label>
                    </div>
                    <button class="btn btn-primary py-2.5">{{ __('app.dashboard.submit') }}</button>
                    <p class="muted text-xs" data-estimate></p>
                    <p class="muted text-xs">{{ __('app.dashboard.queue_note', ['max' => $maxQueries]) }}</p>
                </form>
            </section>

            <section class="card grid gap-2 p-5" aria-labelledby="worker-status">
                <div class="flex items-center justify-between">
                    <h2 id="worker-status" class="text-base font-bold">{{ __('app.dashboard.workers') }}</h2>
                    @if ($onlineWorker)
                        <span class="badge badge-completed">{{ __('app.dashboard.online') }}</span>
                    @else
                        <span class="badge badge-failed">{{ __('app.dashboard.offline') }}</span>
                    @endif
                </div>

                @if ($workers->isEmpty())
                    <p class="muted text-[13px]">{{ __('app.dashboard.no_worker') }}</p>
                    @can('admin')
                        <a class="btn" href="{{ route('admin.workers.setup') }}">{{ __('app.dashboard.set_up_worker') }}</a>
                    @endcan
                @else
                    <p class="text-[13px]">
                        {{ ($onlineWorker ?? $workers->first())->name }}
                        <span class="muted">· {{ __('app.dashboard.last_seen', ['time' => ($onlineWorker ?? $workers->first())->last_seen_at?->diffForHumans() ?? __('app.common.never')]) }}</span>
                    </p>
                    @unless ($onlineWorker)
                        <p class="muted text-[13px]">{!! __('app.dashboard.start_worker', ['file' => '<b dir="ltr">GScraperWorker.bat</b>']) !!}</p>
                    @endunless
                @endif

                @if ($queuedCount)
                    <p class="text-[13px]">{{ trans_choice('app.dashboard.queued_runs', $queuedCount, ['count' => $queuedCount]) }}</p>
                @endif

                @if ($workerLocked)
                    <div class="note {{ $workerReason === 'blocked' ? 'note-bad' : '' }} grid gap-1" role="status">
                        <b class="text-[13px]">{{ __('app.dashboard.lock.'.$workerReason) }}</b>
                        @if (! empty($workerStatus['nextStartAt']))
                            <span class="font-mono text-xl font-bold tabular-nums" dir="ltr" data-countdown="{{ (int) $workerStatus['nextStartAt'] }}">…</span>
                        @endif
                        <span class="muted text-xs">{{ __('app.dashboard.lock.'.$workerReason.'_help') }} {{ __('app.dashboard.lock.reprocess_still_runs') }}</span>
                    </div>
                @endif
            </section>

            {{-- Google rate-limits by IP address, so this budget is per connection, not per PC. --}}
            @foreach ($connections as $conn)
                @php($pct = min(100, $conn['used'] / max(1, $conn['limit']) * 100))
                <section class="card grid gap-2 p-5" aria-labelledby="budget-{{ $loop->index }}">
                    <div class="flex items-baseline justify-between">
                        <h2 id="budget-{{ $loop->index }}" class="text-base font-bold">{{ __('app.dashboard.budget') }}</h2>
                        <b class="text-[15px] tabular-nums" dir="ltr">{{ $conn['used'] }} / {{ $conn['limit'] }}</b>
                    </div>
                    <div class="meter"><i style="width: {{ round($pct, 1) }}%; {{ $pct >= 100 ? 'background: var(--bad)' : ($pct >= 80 ? 'background: var(--hot)' : '') }}"></i></div>
                    <p class="muted text-xs">
                        {{ __('app.dashboard.budget_left', ['remaining' => $conn['remaining'], 'run' => $conn['runBudget']]) }}
                        @if ($conn['workers'] > 1)
                            · {{ __('app.dashboard.budget_shared', ['count' => $conn['workers'], 'names' => implode(__('app.common.list_separator'), $conn['workerNames'])]) }}
                        @endif
                    </p>
                    @unless ($conn['canScrape'])
                        <div class="note note-bad grid gap-1" role="status">
                            <b class="text-[13px]">{{ __('app.dashboard.budget_paused') }}</b>
                            @if ($conn['nextSlotAt'])
                                <span class="font-mono text-2xl font-bold tabular-nums" dir="ltr" data-countdown="{{ $conn['nextSlotAt']->getTimestampMs() }}">…</span>
                                <span class="muted text-xs">{{ __('app.dashboard.budget_resume_at', ['time' => local_time($conn['nextSlotAt'], 'D g:i A')]) }} {{ __('app.dashboard.lock.reprocess_still_runs') }}</span>
                            @else
                                <span class="muted text-xs">{{ __('app.dashboard.budget_slots') }} {{ __('app.dashboard.lock.reprocess_still_runs') }}</span>
                            @endif
                        </div>
                    @endunless
                    <p class="muted text-xs">{{ __('app.dashboard.budget_explain') }}</p>
                </section>
            @endforeach
        </div>

        <section aria-labelledby="recent-runs">
            <div class="mb-2 flex items-center justify-between">
                <h2 id="recent-runs" class="text-base font-bold">{{ __('app.dashboard.recent_runs') }}</h2>
                <a class="link text-[13px]" href="{{ route('runs.index') }}">{{ __('app.dashboard.all_runs') }}</a>
            </div>
            <div class="card grid gap-1 p-2">
                @forelse ($recentRuns as $run)
                    <x-run-card :run="$run" />
                @empty
                    <div class="p-8 text-center">
                        <b class="mb-1 block text-base">{{ __('app.dashboard.empty_title') }}</b>
                        <p class="muted">{!! __('app.dashboard.empty_body', ['action' => '<i>'.e(__('app.dashboard.submit')).'</i>']) !!}</p>
                        <p class="mt-2"><a class="link" href="{{ route('help') }}">{{ __('app.dashboard.empty_help') }}</a></p>
                    </div>
                @endforelse
            </div>
        </section>
    </div>
</x-layouts.app>
