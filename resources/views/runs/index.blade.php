<x-layouts.app :title="__('app.runs.title')">
    <div class="mb-4 flex flex-wrap items-center gap-2">
        <h1 class="me-auto text-xl font-bold">{{ __('app.runs.title') }}</h1>
        <a class="chip" href="{{ route('runs.index') }}" aria-current="{{ $status ? 'false' : 'true' }}">{{ __('app.runs.all') }}</a>
        @foreach (\App\Enums\RunStatus::cases() as $s)
            <a class="chip" href="{{ route('runs.index', ['status' => $s->value]) }}" aria-current="{{ $status === $s ? 'true' : 'false' }}">{{ $s->label() }}</a>
        @endforeach
    </div>

    <div class="card grid gap-1 p-2">
        @forelse ($runs as $run)
            <x-run-card :run="$run" />
        @empty
            <p class="muted p-6 text-center">{{ __('app.runs.empty') }}</p>
        @endforelse
    </div>
    {{ $runs->links() }}
</x-layouts.app>
