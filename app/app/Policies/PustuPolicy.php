<?php

namespace App\Policies;

use App\Models\Pustu;
use App\Models\User;

class PustuPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['ADMINISTRATOR', 'ADMIN_PUSTU'], true);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Pustu $pustu): bool
    {
        return $user->isAdministrator()
            || ($user->isAdminPustu() && $user->adminPustu?->pustu_id === $pustu->id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Pustu $pustu): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Pustu $pustu): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Pustu $pustu): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Pustu $pustu): bool
    {
        return false;
    }
}
