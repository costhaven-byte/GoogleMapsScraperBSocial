@props(['value', 'label', 'field' => null])
<div class="card min-w-[110px] px-4 py-2.5">
    <b class="block text-[22px] tabular-nums" @if ($field) data-field="{{ $field }}" @endif>{{ $value }}</b>
    <span class="muted text-xs">{{ $label }}</span>
</div>
