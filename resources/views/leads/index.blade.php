<x-layouts.app :title="__('app.leads.title')">
    @php
            $url = fn (array $change) => route('leads.index', array_filter(array_merge(
            ['tier' => $filters['tier'], 'priority' => $filters['priority'], 'q' => $filters['q'] ?: null, 'search' => $filters['search'] ?: null, 'all' => $filters['all'] ? 1 : null],
            $change
        ), fn ($v) => $v !== null && $v !== ''));
    @endphp

    <div class="mb-1 flex flex-wrap items-center gap-2">
        <h1 class="me-auto text-xl font-bold">{{ __('app.leads.title') }}</h1>
        <a class="btn" href="{{ route('leads.export', request()->query()) }}">{{ __('app.leads.export') }}</a>
    </div>
    <p class="muted mb-4">{{ __('app.leads.intro') }}
        @if ($filters['all'])
            {{ __('app.leads.showing_all') }} <a class="link" href="{{ $url(['all' => null]) }}">{{ __('app.leads.newest_only_link') }}</a>
        @else
            {{ __('app.leads.newest_only') }} <a class="link" href="{{ $url(['all' => 1]) }}">{{ __('app.leads.show_all_link') }}</a>
        @endif
    </p>

    <div class="mb-3 flex flex-wrap items-center gap-2">
        <form method="GET" action="{{ route('leads.index') }}" class="flex flex-wrap gap-2">
            @foreach (['tier', 'priority'] as $keep)
                @if ($filters[$keep])<input type="hidden" name="{{ $keep }}" value="{{ $filters[$keep] }}">@endif
            @endforeach
            @if ($filters['all'])<input type="hidden" name="all" value="1">@endif
            <input class="input w-60" type="search" name="q" value="{{ $filters['q'] }}" placeholder="{{ __('app.leads.q_placeholder') }}" maxlength="100">
            <input class="input w-60" type="search" name="search" value="{{ $filters['search'] }}" placeholder="{{ __('app.leads.search_placeholder') }}" maxlength="200">
            <button class="btn">{{ __('app.common.apply') }}</button>
        </form>
        <span class="mx-1 h-5 w-px bg-line"></span>
        <a class="chip" href="{{ $url(['tier' => null]) }}" aria-current="{{ $filters['tier'] ? 'false' : 'true' }}">{{ __('app.runs.all_tiers') }}</a>
        @foreach (['A', 'B', 'C'] as $t)
            <a class="chip" href="{{ $url(['tier' => $t]) }}" aria-current="{{ $filters['tier'] === $t ? 'true' : 'false' }}">{{ $t }}</a>
        @endforeach
        <span class="mx-1 h-5 w-px bg-line"></span>
        <a class="chip" href="{{ $url(['priority' => null]) }}" aria-current="{{ $filters['priority'] ? 'false' : 'true' }}">{{ __('app.runs.any_priority') }}</a>
        @foreach (['Hot', 'Warm', 'Cold'] as $p)
            <a class="chip" href="{{ $url(['priority' => $p]) }}" aria-current="{{ $filters['priority'] === $p ? 'true' : 'false' }}">{{ \App\Support\Vocab::priority($p) }}</a>
        @endforeach
    </div>

    <div class="table-wrap">
        <table class="table min-w-[900px]">
            <thead><tr><th>{{ __('app.runs.th.score') }}</th><th>{{ __('app.runs.th.contact') }}</th><th>{{ __('app.runs.th.business') }}</th><th>{{ __('app.runs.th.website') }}</th><th>{{ __('app.runs.th.pitch') }}</th><th>{{ __('app.runs.th.search') }}</th><th>{{ __('app.runs.th.run') }}</th></tr></thead>
            <tbody>
                @forelse ($leads as $b)
                    <tr>
                        <td><span class="text-lg font-bold tabular-nums">{{ $b->score ?? __('app.common.dash') }}</span><br>@if ($b->priority)<span class="badge badge-{{ $b->priority }}">{{ \App\Support\Vocab::priority($b->priority) }}</span>@endif</td>
                        <td>
                            <div class="flex max-w-[260px] items-start gap-2">
                                @if ($b->contact_tier)<span class="tier tier-{{ $b->contact_tier }}">{{ $b->contact_tier }}</span>@endif
                                <div class="[overflow-wrap:anywhere]">
                                    {{ $b->email ?: match (true) {
                                        (bool) $b->d('channels.contactFormUrl') => __('app.runs.detail.contact_form'),
                                        (bool) $b->d('channels.instagram.handle') => __('app.runs.detail.instagram').' @'.$b->d('channels.instagram.handle'),
                                        (bool) $b->d('channels.facebook') => __('app.leads.facebook_no_links'),
                                        default => __('app.common.dash'),
                                    } }}
                                </div>
                            </div>
                        </td>
                        <td><x-ext-link :url="$b->maps_url" :label="$b->name" class="font-semibold" /><div class="muted text-xs">{{ $b->category }}</div></td>
                        <td>{{ \App\Support\Vocab::websiteStatus($b->website_status) }}</td>
                        <td>@foreach ($b->pitch ?? [] as $pitch)<span class="pill">{{ \App\Support\Vocab::pitch($pitch) }}</span>@endforeach</td>
                        <td class="text-[13px]">{{ $b->query }}</td>
                        <td class="text-[13px]"><a class="link" href="{{ route('runs.show', $b->run_id) }}">#{{ $b->run_id }}</a><div class="muted text-xs">{{ local_time($b->run->created_at, __('app.common.date_format')) }}</div></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="muted p-8 text-center">{{ __('app.leads.empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $leads->links() }}
</x-layouts.app>
