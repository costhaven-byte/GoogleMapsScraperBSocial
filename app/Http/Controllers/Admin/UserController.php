<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * There is no public sign-up: admins create every account here.
 */
class UserController extends Controller
{
    public function index(): View
    {
        return view('admin.users.index', ['users' => User::query()->orderBy('name')->paginate(50)]);
    }

    public function create(): View
    {
        return view('admin.users.form', ['user' => new User, 'roles' => UserRole::cases()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:254', 'unique:users,email'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'password' => ['required', 'confirmed', 'max:200', Password::defaults()],
        ]);

        $user = new User(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
        $user->forceFill(['role' => UserRole::from($data['role']), 'is_active' => true])->save();

        return redirect()->route('admin.users.index')->with('status', __('app.flash.account_created', ['email' => $user->email]));
    }

    public function edit(User $user): View
    {
        return view('admin.users.form', ['user' => $user, 'roles' => UserRole::cases()]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:254', Rule::unique('users', 'email')->ignore($user)],
            'role' => ['required', Rule::enum(UserRole::class)],
            'is_active' => ['required', 'boolean'],
            'password' => ['nullable', 'confirmed', 'max:200', Password::defaults()],
        ]);
        $role = UserRole::from($data['role']);
        $active = (bool) $data['is_active'];

        // Never lock the workspace out of its own admin screens.
        $losesAdmin = $user->isAdmin() && $user->is_active && ($role !== UserRole::Admin || ! $active);
        if ($losesAdmin && User::query()->where('role', UserRole::Admin->value)->where('is_active', true)->count() <= 1) {
            throw ValidationException::withMessages(['role' => __('app.validation.only_admin')]);
        }
        if ($user->is($request->user()) && ! $active) {
            throw ValidationException::withMessages(['is_active' => __('app.validation.not_self_deactivate')]);
        }

        $user->fill(['name' => $data['name'], 'email' => $data['email']]);
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->forceFill(['role' => $role, 'is_active' => $active])->save();

        return redirect()->route('admin.users.index')->with('status', __('app.flash.user_saved', ['email' => $user->email]));
    }
}
