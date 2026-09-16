<x-layouts.app :title="__('app.account.title')">
    <div class="max-w-md">
        <h1 class="mb-1 text-xl font-bold">{{ __('app.account.title') }}</h1>
        <p class="muted mb-5">{{ $user->name }} · {{ $user->email }} · {{ $user->role->label() }}</p>

        <form method="POST" action="{{ route('account.password') }}" class="card grid gap-4 p-5">
            @csrf
            @method('PUT')
            <h2 class="text-base font-bold">{{ __('app.account.change_password') }}</h2>
            <label class="label">{{ __('app.account.current_password') }}
                <input class="input" type="password" name="current_password" required autocomplete="current-password" maxlength="200" dir="ltr">
            </label>
            <label class="label">{{ __('app.auth.new_password') }} <span class="font-normal">{{ __('app.auth.password_rule') }}</span>
                <input class="input" type="password" name="password" required autocomplete="new-password" maxlength="200" dir="ltr">
            </label>
            <label class="label">{{ __('app.auth.repeat_password') }}
                <input class="input" type="password" name="password_confirmation" required autocomplete="new-password" maxlength="200" dir="ltr">
            </label>
            <div><button class="btn btn-primary">{{ __('app.account.update') }}</button></div>
        </form>
    </div>
</x-layouts.app>
