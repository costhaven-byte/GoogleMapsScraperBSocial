<x-layouts.guest :title="__('app.auth.reset_title')">
    <form method="POST" action="{{ route('password.email') }}" class="grid gap-4">
        @csrf
        <p class="muted text-[13px]">{{ __('app.auth.reset_intro') }}</p>
        <label class="label">{{ __('app.auth.email') }}
            <input class="input" type="email" name="email" value="{{ old('email') }}" required autofocus maxlength="254" dir="ltr">
        </label>
        @error('email')
            <p class="text-[13px] text-bad" role="alert">{{ $message }}</p>
        @enderror
        <button class="btn btn-primary py-2.5">{{ __('app.auth.send_link') }}</button>
        <a href="{{ route('login') }}" class="muted text-center text-[13px] hover:underline">{{ __('app.auth.back_to_sign_in') }}</a>
    </form>
</x-layouts.guest>
