@if ($paginator->hasPages())
    <nav class="mt-4 flex items-center justify-between gap-2" aria-label="{{ __('app.common.pagination') }}">
        @if ($paginator->onFirstPage())
            <span class="btn" aria-disabled="true" style="opacity:.45">{{ __('app.common.previous') }}</span>
        @else
            <a class="btn" href="{{ $paginator->previousPageUrl() }}" rel="prev">{{ __('app.common.previous') }}</a>
        @endif

        <span class="muted text-xs">
            @if (method_exists($paginator, 'total'))
                {{ __('app.common.range_of_total', ['first' => $paginator->firstItem(), 'last' => $paginator->lastItem(), 'total' => $paginator->total()]) }}
            @else
                {{ __('app.common.page', ['number' => $paginator->currentPage()]) }}
            @endif
        </span>

        @if ($paginator->hasMorePages())
            <a class="btn" href="{{ $paginator->nextPageUrl() }}" rel="next">{{ __('app.common.next') }}</a>
        @else
            <span class="btn" aria-disabled="true" style="opacity:.45">{{ __('app.common.next') }}</span>
        @endif
    </nav>
@endif
