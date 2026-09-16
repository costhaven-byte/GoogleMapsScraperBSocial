<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Worker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkerController extends Controller
{
    public function index(): View
    {
        return view('admin.workers.index', [
            'workers' => Worker::query()->with('creator')->latest('id')->get(),
            // Flashed once by store(); the plain token is never stored.
            'newToken' => session('worker_token'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        [, $token] = Worker::issue($data['name'], $request->user());

        return redirect()->route('admin.workers.index')->with('worker_token', $token);
    }

    public function destroy(Worker $worker): RedirectResponse
    {
        $worker->forceFill(['revoked_at' => now()])->save();

        return back()->with('status', __('app.flash.worker_revoked', ['name' => $worker->name]));
    }

    public function setup(): View
    {
        return view('admin.workers.setup', ['serverUrl' => rtrim(url('/'), '/')]);
    }
}
