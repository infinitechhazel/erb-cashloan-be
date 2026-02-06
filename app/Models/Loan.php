<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Loan extends Model
{
    use HasFactory;

    protected $appends = ['amount'];

    protected $fillable = [
        'user_id',
        'borrower_id',
        'lender_id',
        'loan_officer_id',
        'type',
        'principal_amount',
        'approved_amount',
        'interest_rate',
        'term_months',
        'purpose',
        'outstanding_balance',
        'employment_status',
        'status',
        'approved_at',
        'rejected_at',
        'rejection_reason',
        'start_date',
        'first_payment_date',
        'disbursement_date',
        'notes',
    ];

    protected $casts = [
        'principal_amount' => 'decimal:2',
        'approved_amount' => 'decimal:2',
        'interest_rate' => 'decimal:2',
        'outstanding_balance' => 'float',
        'term_months' => 'integer',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'start_date' => 'date',
        'first_payment_date' => 'date',
    ];

    /**
     * Get the borrower (user) that owns the loan
     */
    public function borrower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'borrower_id');
    }

    /**
     * Get the lender that approved the loan
     */
    public function lender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lender_id');
    }

    /**
     * Get the loan officer assigned to the loan
     */
    public function loanOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'loan_officer_id');
    }

    /**
     * Get the payments for the loan
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Get the documents for the loan
     */
    public function documents(): HasMany
    {
        return $this->hasMany(LoanDocument::class);
    }

    /**
     * Scope a query to only include pending loans
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope a query to only include approved loans
     */
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    /**
     * Scope a query to only include active loans
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Check if loan is pending
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if loan is approved
     */
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * Check if loan is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if loan is rejected
     */
    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    /**
     * Check if loan is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function getAmountAttribute(): string
    {
        return $this->principal_amount ?? '0.00';
    }
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
