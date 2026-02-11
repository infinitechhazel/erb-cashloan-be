<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Loan;
use App\Models\Payment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PaymentSchedulePDFController extends Controller
{
    /**
     * Export payment schedule as PDF
     */
    public function exportPaymentSchedulePDF(Loan $loan)
    {
        try {
            // Get the loan with relationships - borrower and lender ARE users
            $loan->load(['borrower', 'lender']);
            
            // Get payment schedule from database
            $payments = $loan->payments()
                ->orderBy('payment_number', 'asc')
                ->get();

            // If no payments in database, generate them
            if ($payments->isEmpty()) {
                $payments = $this->generatePaymentSchedule($loan);
            }

            // Calculate summary
            $paidPayments = $payments->where('status', 'paid')->count();
            $pendingPayments = $payments->where('status', 'pending')->count();
            $overduePayments = $payments->where('status', 'overdue')->count();
            $missedPayments = $payments->where('status', 'missed')->count();

            // Generate PDF
            $pdf = Pdf::loadView('pdf.payment-schedule', [
                'loan' => $loan,
                'payments' => $payments,
                'summary' => [
                    'paid' => $paidPayments,
                    'pending' => $pendingPayments,
                    'overdue' => $overduePayments,
                    'missed' => $missedPayments,
                ],
            ]);

            // Set paper size and orientation
            $pdf->setPaper('a4', 'portrait');

            // Return PDF download
            return $pdf->download("loan-{$loan->id}-payment-schedule.pdf");
            
        } catch (\Exception $e) {
            Log::error('PDF Generation Error', [
                'loan_id' => $loan->id ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate PDF',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate payment schedule if not in database
     * Matches the frontend logic exactly
     */
    private function generatePaymentSchedule($loan)
    {
        Log::info('=== GENERATING PAYMENT SCHEDULE ===', [
            'loan_id' => $loan->id,
            'term_months' => $loan->term_months
        ]);

        $monthlyPayment = $loan->monthly_payment ??
            ($loan->amount / $loan->term_months);

        Log::info('Monthly payment calculated', [
            'monthly_payment' => $monthlyPayment,
            'loan_amount' => $loan->amount,
            'term_months' => $loan->term_months
        ]);

        // Start from disbursement date or fallback to current date
        $today = Carbon::now();
        $startDate = $loan->disbursement_date
            ? Carbon::parse($loan->disbursement_date)
            : Carbon::create($today->year, $today->month, 1);

        Log::info('Start date determined', [
            'start_date' => $startDate->toDateString(),
            'today' => $today->toDateString(),
            'using_disbursement_date' => !is_null($loan->disbursement_date)
        ]);

        $payments = collect();

        // Generate consecutive months starting from disbursement/start date
        for ($i = 0; $i < $loan->term_months; $i++) {
            // Calculate due date for this payment (end of month, i months after start)
            $dueDate = Carbon::create(
                $startDate->year,
                $startDate->month,
                1
            )->addMonths($i + 1)->subDay(); // Last day of the payment month

            // Determine payment status
            $status = 'pending';
            $todayMidnight = Carbon::create($today->year, $today->month, $today->day);
            $dueDateMidnight = Carbon::create($dueDate->year, $dueDate->month, $dueDate->day);

            if ($dueDateMidnight->lt($todayMidnight)) {
                // Past due date - check if it should be marked as overdue or missed
                $daysPast = $todayMidnight->diffInDays($dueDateMidnight);
                $status = $daysPast > 30 ? 'missed' : 'overdue';
            }

            $payment = (object) [
                'id' => $loan->id * 1000 + ($i + 1),
                'loan_id' => $loan->id,
                'amount' => number_format($monthlyPayment, 2, '.', ''),
                'due_date' => $dueDate->toDateString(),
                'paid_date' => null,
                'status' => $status,
                'payment_number' => $i + 1,
            ];

            $payments->push($payment);

            // Log first, last, and every 6th payment for reference
            if ($i === 0 || $i === $loan->term_months - 1 || $i % 6 === 0) {
                Log::info('Payment generated', [
                    'payment_number' => $i + 1,
                    'due_date' => $dueDate->toDateString(),
                    'amount' => $payment->amount,
                    'status' => $status
                ]);
            }
        }

        Log::info('=== PAYMENT SCHEDULE GENERATION COMPLETED ===', [
            'total_payments_generated' => $payments->count(),
            'expected_payments' => $loan->term_months
        ]);

        return $payments;
    }
}