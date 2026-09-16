<x-layouts.guest :title="__('app.auth.sign_in')">
    <form method="POST" action="{{ route('login') }}" class="grid gap-4">
        @csrf
        <label class="label">{{ __('app.auth.email') }}
            <input class="input" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" maxlength="254" dir="ltr">
        </label>
        <label class="label">{{ __('app.auth.password') }}
            <input class="input" type="password" name="password" required autocomplete="current-password" maxlength="200" dir="ltr">
        </label>
        @error('email')
            <p class="text-[13px] text-bad" role="alert">{{ $message }}</p>
        @enderror
        @error('password')
            <p class="text-[13px] text-bad" role="alert">{{ $message }}</p>
        @enderror
        <label class="flex items-center gap-2 text-[13px]">
            <input type="checkbox" name="remember" value="1"> {{ __('app.auth.remember') }}
        </label>
        <button class="btn btn-primary py-2.5">{{ __('app.auth.sign_in') }}</button>
        <a href="{{ route('password.request') }}" class="muted text-center text-[13px] hover:underline">{{ __('app.auth.forgot') }}</a>
    </form>
</x-layouts.guest>
