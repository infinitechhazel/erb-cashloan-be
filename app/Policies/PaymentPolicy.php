<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    /**
     * Determine if the user can view the payment.
     */
    public function view(User $user, Payment $payment): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $loan = $payment->loan;

        if ($user->isBorrower() && $loan->borrower_id === $user->id) {
            return true;
        }

        if ($user->isLender()) {
            return true;
        }

        if ($user->isLoanOfficer() && $loan->loan_officer_id === $user->id) {
            return true;
        }

        return false;
    }

    /**
     * Determine if the user can record a payment.
     */
    public function pay(User $user, Payment $payment): bool
    {
        $loan = $payment->loan;

        if ($user->isBorrower() && $loan->borrower_id === $user->id) {
            return $payment->status === 'pending';
        }

        if ($user->isAdmin() || $user->isLender() || $user->isLoanOfficer()) {
            return $payment->status === 'pending';
        }

        return false;
    }
}
