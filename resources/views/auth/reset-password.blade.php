<x-layouts.guest :title="__('app.auth.choose_password')">
    <form method="POST" action="{{ route('password.update') }}" class="grid gap-4">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <label class="label">{{ __('app.auth.email') }}
            <input class="input" type="email" name="email" value="{{ old('email', $email) }}" required maxlength="254" dir="ltr">
        </label>
        <label class="label">{{ __('app.auth.new_password') }} <span class="font-normal">{{ __('app.auth.password_rule') }}</span>
            <input class="input" type="password" name="password" required autocomplete="new-password" maxlength="200" dir="ltr">
        </label>
        <label class="label">{{ __('app.auth.repeat_password') }}
            <input class="input" type="password" name="password_confirmation" required autocomplete="new-password" maxlength="200" dir="ltr">
        </label>
        @if ($errors->any())
            <ul class="detail-list text-[13px] text-bad" role="alert">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif
        <button class="btn btn-primary py-2.5">{{ __('app.auth.save_password') }}</button>
    </form>
</x-layouts.guest>
