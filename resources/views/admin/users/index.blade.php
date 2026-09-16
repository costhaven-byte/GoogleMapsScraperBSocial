<x-layouts.app :title="__('app.users.title')">
    <div class="mb-4 flex items-center">
        <h1 class="me-auto text-xl font-bold">{{ __('app.users.title') }}</h1>
        <a class="btn btn-primary" href="{{ route('admin.users.create') }}">{{ __('app.users.add') }}</a>
    </div>
    <div class="table-wrap">
        <table class="table min-w-[640px]">
            <thead><tr><th>{{ __('app.users.name') }}</th><th>{{ __('app.users.email') }}</th><th>{{ __('app.users.role') }}</th><th>{{ __('app.users.status') }}</th><th>{{ __('app.users.last_sign_in') }}</th><th></th></tr></thead>
            <tbody>
                @foreach ($users as $user)
                    <tr>
                        <td class="font-semibold">{{ $user->name }}</td>
                        <td dir="ltr">{{ $user->email }}</td>
                        <td>{{ $user->role->label() }}</td>
                        <td>@if ($user->is_active)<span class="badge badge-completed">{{ __('app.users.active') }}</span>@else<span class="badge badge-failed">{{ __('app.users.deactivated') }}</span>@endif</td>
                        <td class="muted">{{ local_time($user->last_login_at) }}</td>
                        <td class="text-end"><a class="btn" href="{{ route('admin.users.edit', $user) }}">{{ __('app.common.edit') }}</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    {{ $users->links() }}
</x-layouts.app>
