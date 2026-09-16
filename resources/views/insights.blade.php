<x-layouts.app :title="__('app.insights.title')">
    <h1 class="mb-1 text-xl font-bold">{{ __('app.insights.title') }}</h1>
    <p class="muted mb-4">
        {{ __('app.insights.intro') }}
        @if ($all)
            <a class="link" href="{{ route('insights') }}">{{ __('app.insights.count_once_link') }}</a>
        @else
            {{ __('app.insights.counted_once') }} <a class="link" href="{{ route('insights', ['all' => 1]) }}">{{ __('app.insights.count_all_link') }}</a>
        @endif
    </p>

    <div class="table-wrap">
        <table class="table min-w-[860px]">
            <thead><tr><th>{{ __('app.runs.th.search') }}</th><th>{{ __('app.runs.th.runs') }}</th><th>{{ __('app.runs.th.businesses') }}</th><th>{{ __('app.runs.th.inactive') }}</th><th>A</th><th>B</th><th>C</th><th>D</th><th>{{ __('app.runs.mix') }}</th><th>{{ __('app.insights.reachable_of_active') }}</th><th>{{ __('app.runs.th.hot') }}</th></tr></thead>
            <tbody>
                @forelse ($rows as $row)
                    @php
                        $t = ['A' => (int) $row->tier_a, 'B' => (int) $row->tier_b, 'C' => (int) $row->tier_c, 'D' => (int) $row->tier_d];
                        $active = array_sum($t);
                        $reachable = $active - $t['D'];
                    @endphp
                    <tr>
                        <td><a class="link" href="{{ route('leads.index', ['search' => $row->query]) }}">{{ $row->query ?: __('app.insights.no_query') }}</a></td>
                        <td>{{ $row->runs }}</td>
                        <td>{{ $row->total }}</td>
                        <td>{{ $row->inactive }}</td>
                        <td>{{ $t['A'] }}</td><td>{{ $t['B'] }}</td><td>{{ $t['C'] }}</td><td>{{ $t['D'] }}</td>
                        <td><x-tier-stack :t="$t" /></td>
                        <td dir="ltr">{{ $active ? $reachable.'/'.$active.' ('.round($reachable / $active * 100).'%)' : __('app.common.dash') }}</td>
                        <td class="weak">{{ $row->hot }}</td>
                    </tr>
                @empty
                    <tr><td colspan="11" class="muted p-8 text-center">{{ __('app.insights.empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $rows->links() }}
</x-layouts.app>
