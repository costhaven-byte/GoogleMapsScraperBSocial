@props(['run'])
<a href="{{ route('runs.show', $run) }}" class="block rounded-[10px] border border-transparent p-3 hover:border-line hover:bg-soft">
    <div class="flex items-center gap-2">
        <div class="min-w-0 flex-1 truncate font-semibold">{{ $run->title() }}</div>
        <span class="badge badge-{{ $run->status->value }}">{{ $run->status->label() }}</span>
    </div>
    <div class="muted mt-0.5 text-xs">
        {{ $run->type->label() }} · {{ local_time($run->created_at) }}{{ $run->user ? ' · '.$run->user->name : '' }}
    </div>
    @if ($run->status->value === 'completed' || $run->leads_count || $run->inactive_count)
        <div class="mt-1 flex flex-wrap gap-x-3 text-xs">
            <span>{{ __('app.runs.reachable', ['count' => $run->leads_count]) }}</span>
            <span class="weak">{{ __('app.runs.hot', ['count' => $run->hot_count]) }}</span>
            <span>A {{ $run->tier_a_count }} · B {{ $run->tier_b_count }} · C {{ $run->tier_c_count }}</span>
            <span class="muted">{{ __('app.runs.unreachable_inactive', ['unreachable' => $run->unreachable_count, 'inactive' => $run->inactive_count]) }}</span>
        </div>
    @endif
</a>
