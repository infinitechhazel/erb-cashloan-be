<?php

namespace App\Policies;

use App\Models\Loan;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class LoanPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any loans.
     */
    public function viewAny(User $user): bool
    {
        return true; // All authenticated users can list loans (filtered in controller)
    }

    /**
     * Determine whether the user can view the loan.
     */
    public function view(User $user, Loan $loan): bool
    {
        // Admins can view all loans
        if ($user->isAdmin()) {
            return true;
        }

        // Borrowers can view their own loans
        if ($user->isBorrower() && $loan->borrower_id === $user->id) {
            return true;
        }

        // Lenders can view loans they're assigned to
        if ($user->isLender()) {
            return true;
        }

        // Loan officers can view loans they're assigned to
        if ($user->isLoanOfficer() && $loan->loan_officer_id === $user->id) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can create loans.
     */
    public function create(User $user): bool
    {
        // Only borrowers can create loan applications
        return $user->isBorrower();
    }

    /**
     * Determine whether the user can update the loan.
     */
    public function update(User $user, Loan $loan): bool
    {
        // Admins can update any loan
        if ($user->isAdmin()) {
            return true;
        }

        // Loan officers can update loans they're assigned to
        if ($user->isLoanOfficer()) {
            return true;
        }

        // Lenders can update loans they're assigned to
        if ($user->isLender()) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can approve the loan.
     */
    public function approve(User $user, Loan $loan): bool
    {
        // Only admins and loan officers can approve
        return $user->isAdmin() || $user->isLender();
    }

    /**
     * Determine whether the user can reject the loan.
     */
    public function reject(User $user, Loan $loan): bool
    {
        // Only admins and loan officers can reject
        return $user->isAdmin() || $user->isLender();
    }

    /**
     * Determine whether the user can activate the loan.
     */
    public function activate(User $user, Loan $loan): bool
    {
        // Only admins and loan officer can activate approved loans
        return $user->isAdmin() || $user->isLender();
    }

    /**
     * Determine whether the user can delete the loan.
     */
    public function delete(User $user, Loan $loan): bool
    {
        // Only admins can delete loans
        return $user->isAdmin();
    }
}
