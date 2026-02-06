<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;


class Payment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'loan_id',
        'amount',
        'principal_payment',
        'interest_payment',
        'status',
        'due_date',
        'paid_date',
        'days_overdue',
        'late_fee',
        'payment_method',
        'transaction_id',
        'proof_of_payment',
        'notes',
        'verified_by',
        'verified_at',
        'rejection_reason',
    ];

    protected $casts = [
        'due_date' => 'date',
        'paid_date' => 'date',
        'verified_at' => 'datetime',
        'amount' => 'decimal:2',
        'principal_payment' => 'decimal:2',
        'interest_payment' => 'decimal:2',
        'late_fee' => 'decimal:2',
    ];

    /**
     * Get loan relationship
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /**
     * Mark payment as paid by admin/lender verification
     */
    public function markAsPaid(
        ?string $paymentMethod = null,
        ?string $transactionId = null,
        ?string $imagePath = null,
        ?int $adminId = null
    ): void {
        // Update payment record
        $this->update([
            'status' => 'paid',
            'paid_date' => now(),
            'payment_method' => $paymentMethod,
            'transaction_id' => $transactionId,
            'verified_by' => $adminId,
            'verified_at' => now(),
            'proof_of_payment' => $imagePath,
            // 'days_overdue' => 0,
        ]);

        // Update loan outstanding balance
        $loan = $this->loan;
        $currentBalance = floatval($loan->outstanding_balance ?? $loan->amount);
        $newBalance = max(0, $currentBalance - floatval($this->amount));

        $loanColumns = Schema::getColumnListing('loans');
        if (in_array('outstanding_balance', $loanColumns)) {
            $loan->outstanding_balance = $newBalance;
        }

        // Only mark loan as completed if fully paid
        if ($newBalance <= 0.01) {
            $loan->status = 'completed';
        }

        $loan->save();
    }

    /**
     * Mark payment as rejected by admin/lender
     */
    public function markAsRejected(?string $reason = null, ?int $adminId = null): void
    {
        $this->update([
            'status' => 'rejected',
            'rejection_reason' => $reason ?? 'Rejected by admin',
            'verified_by' => $adminId,
            'verified_at' => now(),
        ]);
    }

    /**
     * Check if payment is overdue
     */
    public function isOverdue(): bool
    {
        return ! in_array($this->status, ['paid', 'awaiting_verification']) && $this->due_date < now()->toDateString();
    }

    /**
     * Calculate late fee
     */
    public function calculateLateFee(): float
    {
        if ($this->isOverdue()) {
            // Simple late fee calculation: 5% of amount or $25, whichever is higher
            return max((float) ($this->amount * 0.05), 25);
        }

        return 0;
    }
}
