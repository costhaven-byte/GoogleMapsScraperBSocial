<x-layouts.app :title="__('app.workers.title')">
    <div class="mb-1 flex items-center">
        <h1 class="me-auto text-xl font-bold">{{ __('app.workers.title') }}</h1>
        <a class="btn" href="{{ route('admin.workers.setup') }}">{{ __('app.workers.setup_guide') }}</a>
    </div>
    <p class="muted mb-5">{{ __('app.workers.intro') }}</p>

    @if ($newToken)
        <div class="note mb-5 grid gap-2" role="status">
            <b>{{ __('app.workers.token_once') }}</b>
            <div class="flex gap-2">
                <input class="input font-mono" id="new-token" value="{{ $newToken }}" readonly dir="ltr">
                <button class="btn" type="button" data-copy="#new-token">{{ __('app.common.copy') }}</button>
            </div>
            <span class="text-[13px]">{!! __('app.workers.token_where', [
                'key' => '<code class="code" dir="ltr">WORKER_TOKEN</code>',
                'file' => '<code class="code" dir="ltr">.env</code>',
                'link' => '<a class="link" href="'.route('admin.workers.setup').'">'.e(__('app.workers.setup_guide_lower')).'</a>',
            ]) !!}</span>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.workers.store') }}" class="card mb-5 flex flex-wrap items-end gap-3 p-4">
        @csrf
        <label class="label flex-1">{{ __('app.workers.new_name') }}
            <input class="input" name="name" required maxlength="100" placeholder="{{ __('app.workers.new_name_placeholder') }}">
        </label>
        <button class="btn btn-primary">{{ __('app.workers.create_token') }}</button>
    </form>

    <div class="table-wrap">
        <table class="table min-w-[760px]">
            <thead><tr><th>{{ __('app.users.name') }}</th><th>{{ __('app.users.status') }}</th><th>{{ __('app.workers.last_seen') }}</th><th>{{ __('app.workers.computer') }}</th><th>{{ __('app.workers.daily_usage') }}</th><th>{{ __('app.workers.token') }}</th><th></th></tr></thead>
            <tbody>
                @forelse ($workers as $worker)
                    <tr>
                        <td class="font-semibold">{{ $worker->name }}<div class="muted text-xs font-normal">{{ __('app.workers.added_by', ['name' => $worker->creator?->name ?? __('app.common.dash')]) }}</div></td>
                        <td>
                            @if ($worker->revoked_at)<span class="badge badge-cancelled">{{ __('app.workers.revoked') }}</span>
                            @elseif ($worker->isOnline())<span class="badge badge-completed">{{ __('app.workers.online') }}</span>
                            @else<span class="badge">{{ __('app.workers.offline') }}</span>@endif
                        </td>
                        <td class="muted">{{ $worker->last_seen_at?->diffForHumans() ?? __('app.common.never') }}<div class="text-xs" dir="ltr">{{ $worker->last_ip }}</div></td>
                        <td class="text-[13px]" dir="ltr">{{ $worker->hostname ?? '—' }}<div class="muted text-xs">{{ $worker->version ? 'v'.$worker->version : '' }}</div></td>
                        <td class="text-[13px]" dir="ltr">{{ isset($worker->usage['used']) ? $worker->usage['used'].' / '.$worker->usage['limit'] : '—' }}</td>
                        <td><code class="code" dir="ltr">{{ $worker->token_prefix }}…</code></td>
                        <td class="text-end">
                            @unless ($worker->revoked_at)
                                <form method="POST" action="{{ route('admin.workers.destroy', $worker) }}" data-confirm="{{ __('app.workers.revoke_confirm', ['name' => $worker->name]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-danger">{{ __('app.workers.revoke') }}</button>
                                </form>
                            @endunless
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="muted p-8 text-center">{{ __('app.workers.empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layouts.app>
