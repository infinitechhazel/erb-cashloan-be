<?php

namespace Database\Factories;

use App\Models\Loan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Loan>
 */
class LoanFactory extends Factory
{
    protected $model = Loan::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $principalAmount = fake()->randomFloat(2, 1000, 100000);
        
        return [
            'borrower_id' => User::factory(),
            'lender_id' => null,
            'loan_officer_id' => null,
            'type' => fake()->randomElement(['personal', 'business', 'student', 'home']),
            'principal_amount' => $principalAmount,
            'approved_amount' => null,
            'outstanding_balance' => 0, // ✅ Changed from null to 0
            'interest_rate' => fake()->randomFloat(2, 3, 15),
            'term_months' => fake()->randomElement([12, 24, 36, 48, 60]),
            'purpose' => fake()->sentence(10),
            'employment_status' => fake()->randomElement(['employed', 'self-employed', 'unemployed', 'retired']),
            'status' => fake()->randomElement(['pending', 'approved', 'rejected', 'active', 'completed', 'defaulted']),
            'approved_at' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
            'start_date' => null,
            'first_payment_date' => null,
            'notes' => null,
        ];
    }

    /**
     * Indicate that the loan is pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'approved_at' => null,
            'rejected_at' => null,
            'approved_amount' => null,
            'outstanding_balance' => 0,
            'start_date' => null,
        ]);
    }

    /**
     * Indicate that the loan is approved.
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'approved_at' => now(),
            'approved_amount' => $attributes['principal_amount'],
            'outstanding_balance' => 0,
            'rejected_at' => null,
        ]);
    }

    /**
     * Indicate that the loan is active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
            'lender_id' => User::factory(),
            'approved_at' => now()->subDays(7),
            'approved_amount' => $attributes['principal_amount'],
            'outstanding_balance' => $attributes['principal_amount'],
            'start_date' => now(),
            'first_payment_date' => now()->addMonth(),
        ]);
    }

    /**
     * Indicate that the loan is rejected.
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'rejected',
            'approved_at' => null,
            'rejected_at' => now(),
            'rejection_reason' => fake()->sentence(),
            'outstanding_balance' => 0,
        ]);
    }

    /**
     * Indicate that the loan is completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'lender_id' => User::factory(),
            'approved_at' => now()->subMonths(6),
            'approved_amount' => $attributes['principal_amount'],
            'outstanding_balance' => 0, // ✅ Changed to 0 for completed loans
            'start_date' => now()->subMonths(6),
            'first_payment_date' => now()->subMonths(5),
        ]);
    }

    /**
     * Indicate that the loan is defaulted.
     */
    public function defaulted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'defaulted',
            'lender_id' => User::factory(),
            'approved_at' => now()->subMonths(12),
            'approved_amount' => $attributes['principal_amount'],
            'outstanding_balance' => $attributes['principal_amount'] * 0.75,
            'start_date' => now()->subMonths(12),
            'first_payment_date' => now()->subMonths(11),
        ]);
    }

    /**
     * Indicate that the loan is for a specific borrower.
     */
    public function forBorrower(int $borrowerId): static
    {
        return $this->state(fn (array $attributes) => [
            'borrower_id' => $borrowerId,
        ]);
    }

    /**
     * Indicate that the loan is for a specific lender.
     */
    public function forLender(int $lenderId): static
    {
        return $this->state(fn (array $attributes) => [
            'lender_id' => $lenderId,
        ]);
    }

    /**
     * Indicate that the loan is assigned to a loan officer.
     */
    public function withOfficer(int $officerId): static
    {
        return $this->state(fn (array $attributes) => [
            'loan_officer_id' => $officerId,
        ]);
    }

    /**
     * Set specific notes for the loan.
     */
    public function withNotes(string $notes): static
    {
        return $this->state(fn (array $attributes) => [
            'notes' => $notes,
        ]);
    }
}