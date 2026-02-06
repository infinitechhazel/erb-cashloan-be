<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\Payment;
use App\Services\LoanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PaymentController extends Controller
{
    public function __construct(
        private LoanService $loanService
    ) {
        $this->middleware('auth:sanctum');
    }

    /**
     * Get all payments for a loan
     */
    public function index(Loan $loan)
    {
        $this->authorize('view', $loan);

        $payments = $loan->payments()
            ->orderBy('due_date')
            ->paginate(20);

        return response()->json([
            'payments' => $payments,
        ]);
    }

    public function adminIndex(Request $request)
    {
        try {
            $type = $request->query('type', 'all');
            $search = $request->query('search', '');
            $sortColumn = $request->query('sort_column', 'due_date');
            $sortOrder = $request->query('sort_order', 'asc');
            $perPage = (int) $request->query('per_page', 10);

            // Allowed columns for sorting to prevent SQL injection
            $allowedSorts = ['due_date', 'created_at', 'amount', 'loan_id', 'status'];
            if (! in_array($sortColumn, $allowedSorts)) {
                $sortColumn = 'due_date';
            }
            $sortOrder = $sortOrder === 'desc' ? 'desc' : 'asc';

            $query = Payment::query()->with(['loan.borrower']);

            // Filter type
            $today = now()->toDateString();
            switch ($type) {
                case 'upcoming':
                    $query->where('status', 'pending')
                        ->where('due_date', '>=', $today);
                    break;
                case 'overdue':
                    $query->where('status', 'pending')
                        ->where('due_date', '<', $today);
                    break;
                case 'paid':
                    $query->where('status', 'paid');
                    break;
                case 'awaiting_verification':
                    $query->where('status', 'awaiting_verification');
                    break;
                case 'rejected':
                    $query->where('status', 'rejected');
                    break;
                case 'all':
                default:
                    // no filter
                    break;
            }

            // Global search
            if (! empty($search)) {
                $query->where(function ($q) use ($search) {
                    // Search loan borrower name/email
                    $q->whereHas('loan.borrower', function ($b) use ($search) {
                        $b->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });

                    // Search payment's loan_id or transaction_id
                    $q->orWhere('loan_id', 'like', "%{$search}%")
                        ->orWhere('transaction_id', 'like', "%{$search}%")
                        ->orWhere('id', 'like', "%{$search}%");
                });
            }

            // Sorting
            $query->orderBy($sortColumn, $sortOrder);

            // Paginate
            $payments = $query->paginate($perPage);

            return response()->json($payments);
        } catch (\Exception $e) {
            Log::error('Error fetching admin payments', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => $e->getMessage(), // return actual error during dev
            ], 500);
        }
    }

    /**
     * Get payment details
     */
    public function show(Payment $payment)
    {
        $this->authorize('view', $payment->loan);

        return response()->json([
            'payment' => $payment->load('loan'),
        ]);
    }

    /**
     * Record a payment (for a specific loan)
     */
    public function store(Request $request, Loan $loan)
    {
        $this->authorize('pay', $loan);

        $validated = $request->validate([
            'payment_method' => 'required|in:credit_card,debit_card,bank_transfer,check',
            'transaction_id' => 'sometimes|string|max:100',
        ]);

        // Find the next pending payment
        $payment = $loan->payments()
            ->where('status', 'pending')
            ->orderBy('due_date')
            ->first();

        if (! $payment) {
            return response()->json([
                'message' => 'No pending payments for this loan',
            ], 422);
        }

        // Record the payment
        $payment = $this->loanService->recordPayment(
            $payment,
            $validated['payment_method'],
            $validated['transaction_id'] ?? null
        );

        return response()->json([
            'message' => 'Payment recorded successfully',
            'payment' => $payment->load('loan'),
            'loan' => $payment->loan,
        ]);
    }

    /**
     * Record a payment (general endpoint - creates payment for active loan)
     */
    public function recordPayment(Request $request)
    {
        try {
            $user = auth()->user();
            if (! $user) {
                return response()->json(['message' => 'Unauthenticated'], 401);
            }

            // Validate input
            $validated = $request->validate([
                'payment_id' => 'required|integer|exists:loans,id',
                'amount' => 'required|numeric|min:0.01',
                'payment_method' => 'required|string|in:card,bank,ewallet',
                'payment_details' => 'sometimes|array',
                'proof_of_payment' => 'required|file|image|max:10240', // 10MB
            ]);

            Log::info('Recording payment', [
                'user_id' => $user->id,
                'data' => $validated,
            ]);

            // Get the loan
            $loan = Loan::find($validated['payment_id']);
            if (! $loan) {
                return response()->json(['message' => 'Loan not found'], 404);
            }

            // Check ownership
            if ($loan->borrower_id !== $user->id) {
                return response()->json(['message' => 'Unauthorized to make payment for this loan'], 403);
            }

            // Check loan is active
            if ($loan->status !== 'active') {
                return response()->json(['message' => 'Cannot make payment on a loan that is not active'], 422);
            }

            DB::beginTransaction();
            try {
                // Handle proof_of_payment upload
                $file = $request->file('proof_of_payment');
                $folder = public_path('payment_proofs');

                if (! File::exists($folder)) {
                    File::makeDirectory($folder, 0755, true); // recursive & writable
                }

                $extension = $file->getClientOriginalExtension();
                $fileName = 'payment_'.$validated['payment_id'].'.'.$extension;

                // Move file to public/payment_proofs/
                $file->move($folder, $fileName);

                // Relative path for DB
                $proofPath = '/payment_proofs/'.$fileName;

                Log::info('Proof of payment stored in public folder', [
                    'proof_path' => $proofPath,
                    'full_path' => $folder.DIRECTORY_SEPARATOR.$fileName,
                ]);

                // Create payment
                $paymentColumns = Schema::getColumnListing('payments');
                $paymentData = [
                    'loan_id' => $loan->id,
                    'amount' => $validated['amount'],
                    'due_date' => now(),
                    'status' => 'awaiting_verification', // user submitted, pending admin
                    'payment_method' => $validated['payment_method'],
                ];

                if (in_array('payment_date', $paymentColumns)) {
                    $paymentData['payment_date'] = now();
                }
                if (in_array('transaction_id', $paymentColumns)) {
                    $paymentData['transaction_id'] = 'TXN-'.strtoupper(uniqid());
                }
                if (in_array('reference_number', $paymentColumns)) {
                    $paymentData['reference_number'] = 'REF-'.strtoupper(uniqid());
                }
                if (in_array('proof_of_payment', $paymentColumns)) {
                    $paymentData['proof_of_payment'] = $proofPath;
                }

                $payment = Payment::create($paymentData);

                DB::commit();

                return response()->json([
                    'message' => 'Payment submitted successfully, awaiting verification',
                    'payment' => $payment->load('loan'),
                    'loan' => $loan->fresh(),
                ], 201);

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Database error while recording payment', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                throw $e;
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error recording payment', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to record payment',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    // verify payment
    public function verifyPayment(Request $request, Payment $payment)
    {
        $user = auth()->user();
        // if (! ($user->is_admin || $user->is_lender)) {
        //     return response()->json(['message' => 'Unauthorized'], 403);
        // }

        if ($payment->status !== 'awaiting_verification') {
            return response()->json(['message' => 'Payment not waiting for verification'], 422);
        }

        $action = $request->input('action'); // 'approve' or 'reject'
        $loan = $payment->loan;

        if ($action === 'approve') {
            $payment->markAsPaid(
                $payment->payment_method,
                $payment->transaction_id,
                $payment->proof_of_payment,
                $user->id
            );
        } elseif ($action === 'reject') {
            $payment->markAsRejected(
                $request->input('reason', 'Rejected by admin'),
                $user->id
            );
        } else {
            return response()->json(['message' => 'Invalid action'], 422);
        }

        return response()->json([
            'message' => 'Payment updated successfully',
            'payment' => $payment->fresh(),
            'loan' => $loan->fresh(),
        ]);
    }

    /**
     * Get upcoming payments for authenticated user
     */
    public function upcoming(Request $request)
    {
        try {
            $user = auth()->user();

            if (! $user) {
                return response()->json([
                    'message' => 'Unauthenticated',
                ], 401);
            }

            $days = (int) $request->input('days', 30);

            Log::info('Fetching upcoming payments', [
                'user_id' => $user->id,
                'days' => $days,
            ]);

            // Check if the method exists
            if (! method_exists($this->loanService, 'getUpcomingPayments')) {
                Log::error('Method getUpcomingPayments does not exist on LoanService');

                // Fallback: Query directly
                $payments = Payment::whereHas('loan', function ($query) use ($user) {
                    $query->where('borrower_id', $user->id);
                })
                    ->where('status', 'pending')
                    ->where('due_date', '>=', now())
                    ->where('due_date', '<=', now()->addDays($days))
                    ->with('loan')
                    ->orderBy('due_date')
                    ->paginate(15);

                return response()->json([
                    'payments' => $payments,
                ]);
            }

            $payments = $this->loanService->getUpcomingPayments($user->id, $days)->paginate(15);

            return response()->json([
                'payments' => $payments,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching upcoming payments', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to fetch upcoming payments',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get overdue payments
     */
    public function overdue(Request $request)
    {
        try {
            $user = auth()->user();

            if (! $user) {
                return response()->json([
                    'message' => 'Unauthenticated',
                ], 401);
            }

            Log::info('Fetching overdue payments', [
                'user_id' => $user->id,
            ]);

            // Check if the method exists
            if (! method_exists($this->loanService, 'getOverduePayments')) {
                Log::error('Method getOverduePayments does not exist on LoanService');

                // Fallback: Query directly
                $payments = Payment::whereHas('loan', function ($query) use ($user) {
                    $query->where('borrower_id', $user->id);
                })
                    ->where('status', 'pending')
                    ->where('due_date', '<', now())
                    ->with('loan')
                    ->orderBy('due_date')
                    ->paginate(15);

                return response()->json([
                    'payments' => $payments,
                ]);
            }

            $payments = $this->loanService->getOverduePayments($user->id)->paginate(15);

            return response()->json([
                'payments' => $payments,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching overdue payments', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to fetch overdue payments',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    // public function downloadProof(Payment $payment, Proof $document)
    // {
    //     $this->authorize('view', $payment);

    //     if ($document->loan_id !== $payment->id) {
    //         abort(404);
    //     }

    //     $filePath = public_path($document->file_path);

    //     if (! file_exists($filePath)) {
    //         abort(404, 'File not found');
    //     }

    //     return response()->download($filePath, $document->file_name);
    // }
}
