<?php

namespace App\Services;

use App\Models\Loan;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LoanService
{
    /**
     * Get loan statistics for a user or all (if admin)
     *
     * @param int $userId
     * @param string $role
     * @return array
     */
    public function getUserStatistics(int $userId, string $role): array
    {
        $query = Loan::query();

        // Filter loans by role
        if ($role === 'borrower') {
            $query->where('borrower_id', $userId);
        } elseif ($role === 'lender') {
            $query->where('lender_id', $userId);
        } elseif ($role === 'loan_officer') {
            $query->where('loan_officer_id', $userId);
        }

        // Aggregate counts
        $totalLoans = $query->count();
        $pendingLoans = (clone $query)->where('status', 'pending')->count();
        $approvedLoans = (clone $query)->where('status', 'approved')->count();
        $activeLoans = (clone $query)->where('status', 'active')->count();
        $rejectedLoans = (clone $query)->where('status', 'rejected')->count();
        $completedLoans = (clone $query)->where('status', 'completed')->count();
        $defaultedLoans = (clone $query)->where('status', 'defaulted')->count();

        // Sum amounts
        $totalPrincipal = (clone $query)->sum('principal_amount');
        $totalApproved = (clone $query)->sum('approved_amount');

        // Monthly trend for last 6 months
        $monthlyTrend = Loan::select(
                DB::raw('DATE_FORMAT(created_at, "%Y-%m") as month'),
                DB::raw('COUNT(*) as total_loans'),
                DB::raw('SUM(principal_amount) as total_principal')
            )
            ->when($role === 'borrower', fn($q) => $q->where('borrower_id', $userId))
            ->when($role === 'lender', fn($q) => $q->where('lender_id', $userId))
            ->where('created_at', '>=', Carbon::now()->subMonths(6))
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return [
            'total_loans' => $totalLoans,
            'pending_loans' => $pendingLoans,
            'approved_loans' => $approvedLoans,
            'active_loans' => $activeLoans,
            'rejected_loans' => $rejectedLoans,
            'completed_loans' => $completedLoans,
            'defaulted_loans' => $defaultedLoans,
            'total_principal' => round($totalPrincipal, 2),
            'total_approved' => round($totalApproved, 2),
            'monthly_trend' => $monthlyTrend,
        ];
    }
}
