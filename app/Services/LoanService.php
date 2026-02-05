<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LoanService
{
    /**
     * Create a new loan application
     */
    public function createLoanApplication(
        int $borrowerId,
        string $type,
        float $principalAmount,
        float $interestRate,
        int $termMonths,
        string $purpose,
        ?string $employmentStatus = null
    ): Loan {
        // Calculate total amount with interest
        $totalInterest = ($principalAmount * $interestRate * $termMonths) / (12 * 100);
        $totalAmount = $principalAmount + $totalInterest;

        $loan = Loan::create([
            'borrower_id' => $borrowerId,
            'loan_number' => 'LN-'.strtoupper(uniqid()),
            'type' => $type,
            'principal_amount' => $principalAmount,
            'interest_rate' => $interestRate,
            'term_months' => $termMonths,
            'total_amount' => $totalAmount,
            'outstanding_balance' => 0, // Will be set when activated
            'purpose' => $purpose,
            'employment_status' => $employmentStatus,
            'status' => 'pending',
        ]);

        return $loan;
    }

    /**
     * Approve a loan
     */
    public function approveLoan(
        Loan $loan,
        float $approvedAmount,
        int $lenderId,
        int $loanOfficerId,
        ?float $interestRate = null
    ): Loan {
        $updates = [
            'status' => 'approved',
            'approved_amount' => $approvedAmount,
            'approved_at' => now(),
            'lender_id' => $lenderId,
            'loan_officer_id' => $loanOfficerId,
        ];

        // If interest rate is provided, update it and recalculate total
        if ($interestRate !== null) {
            $updates['interest_rate'] = $interestRate;
            $totalInterest = ($approvedAmount * $interestRate * $loan->term_months) / (12 * 100);
            $updates['total_amount'] = $approvedAmount + $totalInterest;
        }

        $loan->update($updates);

        return $loan->fresh();
    }

    /**
     * Reject a loan
     */
    public function rejectLoan(Loan $loan, string $reason): Loan
    {
        $loan->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'rejected_at' => now(),
        ]);

        return $loan->fresh();
    }

    /**
     * Activate a loan and generate payment schedule
     */
    public function activateLoan(
        Loan $loan,
        ?Carbon $startDate = null,
        ?Carbon $firstPaymentDate = null,
        ?string $notes = null
    ): Loan {
        DB::beginTransaction();

        try {
            if ($loan->approved_amount === null) {
                throw new \Exception('Loan must be approved before activation');
            }

            $startDate = $startDate ?? now();

            $principal = $loan->approved_amount;
            $interestRate = $loan->interest_rate;
            $termMonths = $loan->term_months;

            $totalInterest = ($principal * $interestRate * $termMonths) / (12 * 100);
            $totalPayable = round($principal + $totalInterest, 2);

            $updateData = [
                'status' => 'active',
                'start_date' => $startDate,
                'first_payment_date' => $firstPaymentDate,
                'disbursement_date' => $startDate,
                'outstanding_balance' => $totalPayable,
            ];

            if ($notes !== null) {
                $updateData['notes'] = $notes;
            }

            $loan->update($updateData);

            // Generate payment schedule
            $this->generatePaymentSchedule($loan, $firstPaymentDate);

            DB::commit();

            return $loan->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Generate payment schedule for a loan
     */
    /**
     * Generate amortized payment schedule for a loan
     */
    public function generatePaymentSchedule(Loan $loan, ?Carbon $firstPaymentDate = null): void
    {
        /**
         * SAFETY CHECK
         * If term_months is missing or invalid, schedule generation must stop.
         * Otherwise, the loop below will never run.
         */
        if (! $loan->term_months || $loan->term_months <= 0) {
            throw new \Exception('Loan term_months is missing or invalid');
        }

        /**
         * Remove any existing payment schedule
         * (prevents duplicate schedules on re-activation)
         */
        $loan->payments()->delete();

        /**
         * PRINCIPAL & TERMS
         */
        $principal = $loan->approved_amount;     // Loan amount to amortize
        $interestRate = $loan->interest_rate / 100; // Convert % to decimal
        $termMonths = $loan->term_months;          // Number of payments
        $monthlyRate = $interestRate / 12;          // Monthly interest rate

        /**
         * MONTHLY PAYMENT CALCULATION
         * Uses standard amortization formula:
         *
         * M = P * [r(1+r)^n] / [(1+r)^n − 1]
         */
        if ($monthlyRate > 0) {
            $monthlyPayment = $principal *
                ($monthlyRate * pow(1 + $monthlyRate, $termMonths)) /
                (pow(1 + $monthlyRate, $termMonths) - 1);
        } else {
            // Zero-interest loan
            $monthlyPayment = $principal / $termMonths;
        }

        // Ensure currency precision
        $monthlyPayment = round($monthlyPayment, 2);

        /**
         * PAYMENT START DATE
         * If first_payment_date is provided, it is already the FIRST due date.
         */
        $startDate = $firstPaymentDate ?? $loan->disbursement_date;

        /**
         * GENERATE PAYMENT ROWS
         */
        for ($i = 1; $i <= $termMonths; $i++) {

            // First payment = startDate, next payments follow monthly
            $dueDate = Carbon::parse($startDate)->addMonths($i - 1);

            /**
             * Default payment amount
             */
            $amount = $monthlyPayment;

            /**
             * LAST PAYMENT ADJUSTMENT
             * Fixes rounding differences so total equals principal exactly.
             */
            if ($i === $termMonths) {
                $amount = round(
                    $principal - ($monthlyPayment * ($termMonths - 1)),
                    2
                );
            }

            /**
             * Create payment record
             */
            Payment::create([
                'loan_id' => $loan->id,
                'amount' => $amount,
                'due_date' => $dueDate,
                'status' => 'pending',
            ]);

            /**
             * Optional debug log
             */
            Log::info('Payment scheduled', [
                'loan_id' => $loan->id,
                'payment_number' => $i,
                'amount' => $amount,
                'due_date' => $dueDate->format('Y-m-d'),
            ]);
        }
    }

    /**
     * Get upcoming payments for a user
     */
    public function getUpcomingPayments(int $userId, int $days = 30)
    {
        return Payment::whereHas('loan', function ($query) use ($userId) {
            $query->where('borrower_id', $userId)
                ->where('status', 'active');
        })
            ->where('status', 'pending')
            ->where('due_date', '>=', now())
            ->where('due_date', '<=', now()->addDays($days))
            ->with('loan')
            ->orderBy('due_date');
    }

    /**
     * Get overdue payments for a user
     */
    public function getOverduePayments(int $userId)
    {
        return Payment::whereHas('loan', function ($query) use ($userId) {
            $query->where('borrower_id', $userId)
                ->where('status', 'active');
        })
            ->where('status', 'pending')
            ->where('due_date', '<', now())
            ->with('loan')
            ->orderBy('due_date');
    }

    /**
     * Record a payment
     */
    public function recordPayment(
        Payment $payment,
        string $paymentMethod,
        ?string $transactionId = null
    ): Payment {
        DB::beginTransaction();

        try {
            $payment->update([
                'status' => 'paid',
                'paid_date' => now(),
                'payment_method' => $paymentMethod,
                'transaction_id' => $transactionId,
            ]);

            // Update loan's outstanding balance
            $loan = $payment->loan;
            $loan->outstanding_balance = max(0, $loan->outstanding_balance - $payment->amount);

            // Check if loan is fully paid
            $remainingPayments = $loan->payments()
                ->where('status', 'pending')
                ->count();

            if ($remainingPayments === 0 || $loan->outstanding_balance <= 0.01) {
                $loan->status = 'completed';
                $loan->completed_at = now();
            }

            $loan->save();

            DB::commit();

            Log::info('Payment recorded', [
                'payment_id' => $payment->id,
                'loan_id' => $loan->id,
                'amount' => $payment->amount,
                'remaining_balance' => $loan->outstanding_balance,
            ]);

            return $payment->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to record payment', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get user statistics
     */
    public function getUserStatistics(int $userId, string $role): array
    {
        if ($role === 'borrower') {
            return [
                'total_loans' => Loan::where('borrower_id', $userId)->count(),
                'active_loans' => Loan::where('borrower_id', $userId)->where('status', 'active')->count(),
                'total_borrowed' => Loan::where('borrower_id', $userId)->where('status', '!=', 'rejected')->sum('principal_amount'),
                'total_outstanding' => Loan::where('borrower_id', $userId)->where('status', 'active')->sum('outstanding_balance'),
            ];
        }

        if ($role === 'lender' || $role === 'admin') {
            return [
                'total_loans' => Loan::count(),
                'pending_loans' => Loan::where('status', 'pending')->count(),
                'active_loans' => Loan::where('status', 'active')->count(),
                'total_disbursed' => Loan::whereIn('status', ['active', 'completed'])->sum('principal_amount'),
            ];
        }

        return [];
    }
}
