<?php

namespace App\Policies;

use App\Enums\RunType;
use App\Models\Run;
use App\Models\User;

/**
 * Runs are a shared workspace: every active user can see every run.
 * Changing or deleting one is limited to its owner and admins.
 */
class RunPolicy
{
    public function view(User $user, Run $run): bool
    {
        return $user->is_active;
    }

    public function cancel(User $user, Run $run): bool
    {
        return $run->isActive() && $this->owns($user, $run);
    }

    public function reprocess(User $user, Run $run): bool
    {
        return $user->is_active && ! $run->isActive() && $run->places_count > 0;
    }

    public function delete(User $user, Run $run): bool
    {
        return ! $run->isActive() && $this->owns($user, $run);
    }

    private function owns(User $user, Run $run): bool
    {
        return $user->isAdmin() || ($run->user_id !== null && $run->user_id === $user->id);
    }
}
