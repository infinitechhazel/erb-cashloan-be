<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'role',
        'phone',
        'profile_url',
        'address',
        'city',
        'state',
        'postal_code',
        'country',
        'credit_score',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    /**
     * ✅ CRITICAL FIX: Always append 'name' to JSON
     */
    protected $appends = ['name'];

    /**
     * Get all loans where user is borrower
     */
    public function borrowedLoans(): HasMany
    {
        return $this->hasMany(Loan::class, 'borrower_id');
    }

    /**
     * Get all loans where user is lender
     */
    public function lentLoans(): HasMany
    {
        return $this->hasMany(Loan::class, 'lender_id');
    }

    /**
     * Get all loans processed by loan officer
     */
    public function processedLoans(): HasMany
    {
        return $this->hasMany(Loan::class, 'loan_officer_id');
    }

    /**
     * Check if user is admin
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Check if user is lender
     */
    public function isLender(): bool
    {
        return $this->role === 'lender';
    }

    /**
     * Check if user is borrower
     */
    public function isBorrower(): bool
    {
        return $this->role === 'borrower';
    }

    /**
     * Check if user is loan officer
     */
    public function isLoanOfficer(): bool
    {
        return $this->role === 'loan_officer';
    }

    /**
     * ✅ ACCESSOR: Creates 'name' attribute from first_name + last_name
     * This will automatically be included in JSON because of $appends
     */
    public function getNameAttribute(): string
    {
        return trim(($this->first_name ?? '').' '.($this->last_name ?? ''));
    }

    /**
     * Backwards compatibility accessor
     */
    public function getFullNameAttribute(): string
    {
        return $this->name;
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class, 'borrower_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
