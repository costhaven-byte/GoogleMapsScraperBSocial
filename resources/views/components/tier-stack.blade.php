@props(['t'])
@php($total = max(1, ($t['A'] ?? 0) + ($t['B'] ?? 0) + ($t['C'] ?? 0) + ($t['D'] ?? 0)))
<div class="stack" title="A {{ $t['A'] ?? 0 }} · B {{ $t['B'] ?? 0 }} · C {{ $t['C'] ?? 0 }} · D {{ $t['D'] ?? 0 }}">
    @foreach (['A' => 'var(--tier-a)', 'B' => 'var(--tier-b)', 'C' => 'var(--tier-c)', 'D' => 'var(--tier-d)'] as $k => $color)
        <i style="width: {{ round((($t[$k] ?? 0) / $total) * 100, 2) }}%; background: {{ $color }}"></i>
    @endforeach
</div>
