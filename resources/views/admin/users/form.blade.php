<x-layouts.app :title="$user->exists ? __('app.users.edit', ['name' => $user->name]) : __('app.users.add')">
    <div class="max-w-md">
        <h1 class="mb-5 text-xl font-bold">{{ $user->exists ? __('app.users.edit', ['name' => $user->name]) : __('app.users.add') }}</h1>

        <form method="POST" action="{{ $user->exists ? route('admin.users.update', $user) : route('admin.users.store') }}" class="card grid gap-4 p-5">
            @csrf
            @if ($user->exists) @method('PUT') @endif
            <label class="label">{{ __('app.users.name') }}
                <input class="input" name="name" value="{{ old('name', $user->name) }}" required maxlength="100">
            </label>
            <label class="label">{{ __('app.users.email') }}
                <input class="input" type="email" name="email" value="{{ old('email', $user->email) }}" required maxlength="254" dir="ltr">
            </label>
            <label class="label">{{ __('app.users.role') }}
                <select class="input" name="role">
                    @foreach ($roles as $role)
                        <option value="{{ $role->value }}" @selected(old('role', $user->role?->value ?? 'member') === $role->value)>{{ $role->label() }}</option>
                    @endforeach
                </select>
                <span class="font-normal">{{ __('app.users.role_help') }}</span>
            </label>
            @if ($user->exists)
                <label class="label">{{ __('app.users.status') }}
                    <select class="input" name="is_active">
                        <option value="1" @selected((string) old('is_active', (int) $user->is_active) === '1')>{{ __('app.users.active') }}</option>
                        <option value="0" @selected((string) old('is_active', (int) $user->is_active) === '0')>{{ __('app.users.deactivated_option') }}</option>
                    </select>
                </label>
            @endif
            <label class="label">{{ $user->exists ? __('app.auth.new_password') : __('app.auth.password') }} <span class="font-normal">{{ $user->exists ? __('app.users.password_keep') : __('app.auth.password_rule') }}</span>
                <input class="input" type="password" name="password" @required(! $user->exists) autocomplete="new-password" maxlength="200" dir="ltr">
            </label>
            <label class="label">{{ __('app.users.repeat_password') }}
                <input class="input" type="password" name="password_confirmation" @required(! $user->exists) autocomplete="new-password" maxlength="200" dir="ltr">
            </label>
            <div class="flex gap-2">
                <button class="btn btn-primary">{{ $user->exists ? __('app.common.save') : __('app.users.create') }}</button>
                <a class="btn" href="{{ route('admin.users.index') }}">{{ __('app.common.cancel') }}</a>
            </div>
        </form>
    </div>
</x-layouts.app>
