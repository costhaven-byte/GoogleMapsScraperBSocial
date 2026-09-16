@props(['url', 'label' => null])
@php($safe = \App\Support\SafeUrl::http($url))
@if ($safe)<a href="{{ $safe }}" target="_blank" rel="noopener noreferrer nofollow" {{ $attributes->merge(['class' => 'link']) }}>{{ $label ?? $safe }}</a>@else<span {{ $attributes }}>{{ $label ?? $url }}</span>@endif
