<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class BorrowerPolicy
{
    use HandlesAuthorization;

    /**
     * Determine if the user can view any borrowers.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin(); // Only admins can list all borrowers
    }

    /**
     * Determine if the user can view a borrower.
     */
    public function view(User $user, User $borrower): bool
    {
        return $user->isAdmin() || $user->id === $borrower->id;
    }

    /**
     * Determine if the user can create borrowers.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine if the user can update a borrower.
     */
    public function update(User $user, User $borrower): bool
    {
        return $user->isAdmin() || $user->id === $borrower->id;
    }

    /**
     * Determine if the user can delete a borrower.
     */
    public function delete(User $user, User $borrower): bool
    {
        return $user->isAdmin();
    }
}
