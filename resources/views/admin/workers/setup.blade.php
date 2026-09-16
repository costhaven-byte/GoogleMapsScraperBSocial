<x-layouts.app :title="__('app.workers.setup.title')">
    @php($code = fn (string $text) => '<code class="code" dir="ltr">'.e($text).'</code>')
    <div class="max-w-3xl">
        <h1 class="mb-1 text-xl font-bold">{{ __('app.workers.setup.heading') }}</h1>
        <p class="muted mb-6">{!! __('app.workers.setup.intro', ['file' => $code('docs/WORKER_SETUP.md')]) !!}</p>

        <ol class="grid gap-5">
            <li class="card p-5">
                <h2 class="mb-2 font-bold">{{ __('app.workers.setup.step1') }}</h2>
                <p>{{ __('app.workers.setup.step1_body') }}</p>
            </li>
            <li class="card p-5">
                <h2 class="mb-2 font-bold">{{ __('app.workers.setup.step2') }}</h2>
                <p>{!! __('app.workers.setup.step2_body', ['folder' => $code('worker'), 'path' => $code('C:\\GScraperWorker')]) !!}</p>
                <pre class="log mt-2" dir="ltr">npm install
npx playwright install chromium</pre>
            </li>
            <li class="card p-5">
                <h2 class="mb-2 font-bold">{{ __('app.workers.setup.step3') }}</h2>
                <p>{!! __('app.workers.setup.step3_body', ['link' => '<a class="link" href="'.route('admin.workers.index').'">'.e(__('app.workers.title')).'</a>']) !!}</p>
            </li>
            <li class="card p-5">
                <h2 class="mb-2 font-bold">{{ __('app.workers.setup.step4') }}</h2>
                <p>{!! __('app.workers.setup.step4_body', ['example' => $code('.env.example'), 'env' => $code('.env')]) !!}</p>
                <div class="mt-2 flex gap-2">
                    <textarea class="input font-mono text-xs" id="worker-env" rows="2" readonly dir="ltr">SERVER_URL={{ $serverUrl }}
WORKER_TOKEN=paste-your-token-here</textarea>
                    <button class="btn self-start" type="button" data-copy="#worker-env">{{ __('app.common.copy') }}</button>
                </div>
            </li>
            <li class="card p-5">
                <h2 class="mb-2 font-bold">{{ __('app.workers.setup.step5') }}</h2>
                <p>{!! __('app.workers.setup.step5_body', ['command' => $code('npm run login')]) !!}</p>
            </li>
            <li class="card p-5">
                <h2 class="mb-2 font-bold">{{ __('app.workers.setup.step6') }}</h2>
                <p>{!! __('app.workers.setup.step6_body', [
                    'bat' => $code('GScraperWorker.bat'),
                    'command' => $code('npm start'),
                    'online' => '<b>'.e(__('app.workers.online')).'</b>',
                ]) !!}</p>
            </li>
        </ol>
    </div>
</x-layouts.app>
