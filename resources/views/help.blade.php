{{-- The How-to-use guide. Content lives in lang/<locale>/help.php so both
     languages render through this one template. --}}
@php($sections = (array) __('help.sections'))

<x-layouts.app :title="__('help.title')">
    <div class="mx-auto max-w-3xl">
        <h1 class="text-xl font-bold tracking-tight">{{ __('help.heading') }}</h1>
        <p class="muted mt-1 mb-6">{{ __('help.intro') }}</p>

        <nav class="card mb-8 p-5" aria-labelledby="help-contents">
            <h2 id="help-contents" class="detail-h">{{ __('help.contents') }}</h2>
            <ol class="grid gap-1 ps-5 text-[13px] [list-style:decimal]">
                @foreach ($sections as $key => $section)
                    <li><a class="link" href="#{{ $key }}">{{ $section['title'] }}</a></li>
                @endforeach
            </ol>
        </nav>

        @foreach ($sections as $key => $section)
            <section id="{{ $key }}" class="mb-8 scroll-mt-4">
                <h2 class="mb-1 text-base font-bold">{{ $loop->iteration }}. {{ $section['title'] }}</h2>
                @isset($section['intro'])
                    <p class="muted mb-3">{{ $section['intro'] }}</p>
                @endisset

                @isset($section['steps'])
                    <ol class="card grid gap-2 p-5 ps-9 [list-style:decimal]">
                        @foreach ($section['steps'] as $step)
                            <li>{{ $step }}</li>
                        @endforeach
                    </ol>
                @endisset

                @isset($section['items'])
                    <dl class="card grid gap-4 p-5">
                        @foreach ($section['items'] as $term => $description)
                            <div>
                                <dt class="font-semibold">{{ $term }}</dt>
                                <dd class="muted mt-0.5 ms-0">{{ $description }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endisset
            </section>
        @endforeach

        <a class="btn" href="{{ route('dashboard') }}">{{ __('help.back_to_app') }}</a>
    </div>
</x-layouts.app>
