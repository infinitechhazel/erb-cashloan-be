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
use Illuminate\Support\Facades\Storage;

class PaymentController extends Controller
{
    public function __construct(
        private LoanService $loanService
    ) {
        $this->middleware('auth:sanctum');
    }

    // ========================================
    // LENDER-SPECIFIC METHODS (FIXED)
    // ========================================

    /**
     * Get all payments for the authenticated lender
     * GET /api/lender/payments
     *
     * FIXED: Using direct JOIN instead of whereHas for better performance and reliability
     */
    public function indexForLender(Request $request)
    {
        try {
            $user = $request->user();

            Log::info('Fetching payments for lender', ['lender_id' => $user->id]);

            // FIXED: Use direct JOIN approach - more reliable and performant
            $query = DB::table('payments')
                ->join('loans', 'payments.loan_id', '=', 'loans.id')
                ->join('users as borrowers', 'loans.borrower_id', '=', 'borrowers.id')
                ->where('loans.lender_id', $user->id)
                ->select(
                    'payments.*',
                    'loans.id as loan_id_full',
                    'loans.type as loan_type',
                    'loans.principal_amount',
                    'loans.approved_amount',
                    'loans.interest_rate',
                    'loans.status as loan_status',
                    'loans.term_months',
                    'loans.purpose',
                    'loans.outstanding_balance',
                    DB::raw('CONCAT(borrowers.first_name, " ", borrowers.last_name) as borrower_name'),
                    'borrowers.email as borrower_email',
                    'borrowers.first_name as borrower_first_name',
                    'borrowers.last_name as borrower_last_name'
                );

            // Filter by type/status
            if ($request->has('type') && $request->type !== 'all') {
                $type = $request->type;

                switch ($type) {
                    case 'upcoming':
                        $query->where('payments.status', 'pending')
                            ->where('payments.due_date', '>=', now());
                        break;

                    case 'overdue':
                        $query->where(function ($q) {
                            $q->whereIn('payments.status', ['late', 'missed'])
                                ->orWhere(function ($subQ) {
                                    $subQ->where('payments.status', 'pending')
                                        ->where('payments.due_date', '<', now());
                                });
                        });
                        break;

                    case 'awaiting_verification':
                        $query->where('payments.status', 'awaiting_verification');
                        break;

                    case 'rejected':
                        $query->where('payments.status', 'rejected');
                        break;

                    case 'paid':
                        $query->where('payments.status', 'paid');
                        break;

                    default:
                        $query->where('payments.status', $type);
                        break;
                }
            }

            // Search functionality
            if ($request->has('search') && ! empty($request->search)) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('payments.transaction_id', 'like', "%{$search}%")
                        ->orWhere('payments.loan_id', 'like', "%{$search}%")
                        ->orWhere('borrowers.first_name', 'like', "%{$search}%")
                        ->orWhere('borrowers.last_name', 'like', "%{$search}%")
                        ->orWhereRaw("CONCAT(borrowers.first_name, ' ', borrowers.last_name) LIKE ?", ["%{$search}%"]);
                });
            }

            // Sorting
            $sortField = $request->input('sort_field', 'due_date');
            $sortOrder = $request->input('sort_order', 'asc');

            // Map frontend field names to database columns
            $fieldMapping = [
                'id' => 'payments.id',
                'transaction_id' => 'payments.transaction_id',
                'amount' => 'payments.amount',
                'due_date' => 'payments.due_date',
                'status' => 'payments.status',
                'created_at' => 'payments.created_at',
                'loan_id' => 'payments.loan_id',
            ];

            $dbSortField = $fieldMapping[$sortField] ?? 'payments.due_date';
            $query->orderBy($dbSortField, $sortOrder);

            // Get total count before pagination
            $total = $query->count();

            // Pagination
            $perPage = (int) $request->input('per_page', 10);
            $page = (int) $request->input('page', 1);
            $offset = ($page - 1) * $perPage;

            $payments = $query->offset($offset)->limit($perPage)->get();

            // Transform the results to match expected structure
            $transformedPayments = $payments->map(function ($payment) {
                return [
                    'id' => $payment->id,
                    'loan_id' => $payment->loan_id,
                    'transaction_id' => $payment->transaction_id,
                    'amount' => $payment->amount,
                    'principal_amount' => $payment->principal_amount ?? null,
                    'interest_amount' => $payment->interest_amount ?? null,
                    'due_date' => $payment->due_date,
                    'payment_date' => $payment->payment_date,
                    'paid_date' => $payment->paid_date,
                    'status' => $payment->status,
                    'payment_method' => $payment->payment_method,
                    'proof_of_payment' => $payment->proof_of_payment,
                    'verified_at' => $payment->verified_at,
                    'verified_by' => $payment->verified_by,
                    'rejection_reason' => $payment->rejection_reason,
                    'notes' => $payment->notes,
                    'late_fee' => $payment->late_fee ?? '0.00',
                    'days_late' => $payment->days_late ?? 0,
                    'created_at' => $payment->created_at,
                    'updated_at' => $payment->updated_at,
                    'loan' => [
                        'id' => $payment->loan_id,
                        'type' => $payment->loan_type,
                        'principal_amount' => $payment->principal_amount,
                        'approved_amount' => $payment->approved_amount,
                        'interest_rate' => $payment->interest_rate,
                        'status' => $payment->loan_status,
                        'term_months' => $payment->term_months,
                        'purpose' => $payment->purpose,
                        'outstanding_balance' => $payment->outstanding_balance,
                        'borrower' => [
                            'name' => $payment->borrower_name,
                            'first_name' => $payment->borrower_first_name,
                            'last_name' => $payment->borrower_last_name,
                            'email' => $payment->borrower_email,
                        ],
                    ],
                ];
            });

            // Build pagination response
            $response = [
                'current_page' => $page,
                'data' => $transformedPayments,
                'first_page_url' => $request->url().'?page=1',
                'from' => $offset + 1,
                'last_page' => (int) ceil($total / $perPage),
                'last_page_url' => $request->url().'?page='.ceil($total / $perPage),
                'links' => [],
                'next_page_url' => $page < ceil($total / $perPage) ? $request->url().'?page='.($page + 1) : null,
                'path' => $request->url(),
                'per_page' => $perPage,
                'prev_page_url' => $page > 1 ? $request->url().'?page='.($page - 1) : null,
                'to' => min($offset + $perPage, $total),
                'total' => $total,
            ];

            Log::info('Lender payments fetched successfully', [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
            ]);

            return response()->json($response);

        } catch (\Exception $e) {
            Log::error('Error fetching lender payments: '.$e->getMessage());
            Log::error('Stack trace: '.$e->getTraceAsString());

            return response()->json([
                'message' => 'Failed to fetch payments',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get upcoming payments for lender (due in the future, not paid)
     * GET /api/lender/payments/upcoming
     */
    public function upcomingForLender(Request $request)
    {
        $user = $request->user();

        $query = Payment::with(['loan.borrower'])
            ->whereHas('loan', function ($q) use ($user) {
                $q->where('lender_id', $user->id);
            })
            ->where('status', 'pending')
            ->where('due_date', '>=', now());

        $perPage = $request->input('per_page', 10);

        return response()->json($query->paginate($perPage));
    }

    /**
     * Get overdue payments for lender
     * GET /api/lender/payments/overdue
     */
    public function overdueForLender(Request $request)
    {
        $user = $request->user();

        $query = Payment::with(['loan.borrower'])
            ->whereHas('loan', function ($q) use ($user) {
                $q->where('lender_id', $user->id);
            })
            ->where(function ($q) {
                $q->whereIn('status', ['late', 'missed'])
                    ->orWhere(function ($subQ) {
                        $subQ->where('status', 'pending')
                            ->where('due_date', '<', now());
                    });
            });

        $perPage = $request->input('per_page', 10);

        return response()->json($query->paginate($perPage));
    }

    /**
     * Get payments awaiting verification
     * GET /api/lender/payments/awaiting-verification
     */
    public function awaitingVerificationForLender(Request $request)
    {
        $user = $request->user();

        $query = Payment::with(['loan.borrower'])
            ->whereHas('loan', function ($q) use ($user) {
                $q->where('lender_id', $user->id);
            })
            ->where('status', 'awaiting_verification');

        $perPage = $request->input('per_page', 10);

        return response()->json($query->paginate($perPage));
    }

    /**
     * Get rejected payments
     * GET /api/lender/payments/rejected
     */
    public function rejectedForLender(Request $request)
    {
        $user = $request->user();

        $query = Payment::with(['loan.borrower'])
            ->whereHas('loan', function ($q) use ($user) {
                $q->where('lender_id', $user->id);
            })
            ->where('status', 'rejected');

        $perPage = $request->input('per_page', 10);

        return response()->json($query->paginate($perPage));
    }

    /**
     * Get paid payments
     * GET /api/lender/payments/paid
     */
    public function paidForLender(Request $request)
    {
        $user = $request->user();

        $query = Payment::with(['loan.borrower'])
            ->whereHas('loan', function ($q) use ($user) {
                $q->where('lender_id', $user->id);
            })
            ->where('status', 'paid');

        $perPage = $request->input('per_page', 10);

        return response()->json($query->paginate($perPage));
    }

    /**
     * Get payments for borrower
     * GET /api/borrower/payments
     */
    public function indexForBorrower(Request $request)
    {
        $user = $request->user();

        $query = Payment::with(['loan'])
            ->whereHas('loan', function ($q) use ($user) {
                $q->where('borrower_id', $user->id);
            });

        $perPage = $request->input('per_page', 10);

        return response()->json($query->paginate($perPage));
    }

    /**
     * Verify a payment (approve or reject)
     * POST /api/payments/{payment}/verify
     */
    public function verify(Request $request, Payment $payment)
    {
        $request->validate([
            'action' => 'required|in:approve,reject',
            'reason' => 'required_if:action,reject|string|max:500',
        ]);

        $user = $request->user();

        // Check if the payment belongs to a loan managed by this lender
        if ($payment->loan->lender_id !== $user->id) {
            return response()->json([
                'message' => 'Unauthorized to verify this payment',
            ], 403);
        }

        if ($request->action === 'approve') {
            $payment->update([
                'status' => 'paid',
                'verified_at' => now(),
                'paid_date' => now(),
            ]);

            // Update loan outstanding balance
            $loan = $payment->loan;
            $newBalance = floatval($loan->outstanding_balance) - floatval($payment->amount);
            $loan->outstanding_balance = max(0, $newBalance); // Don't go negative
            $loan->save();

            return response()->json([
                'message' => 'Payment approved successfully',
                'payment' => $payment->fresh(['loan.borrower']),
            ]);
        } else {
            $payment->update([
                'status' => 'rejected',
                'rejection_reason' => $request->reason,
                'verified_at' => now(),
            ]);

            return response()->json([
                'message' => 'Payment rejected',
                'payment' => $payment->fresh(['loan.borrower']),
            ]);
        }
    }

    // ========================================
    // EXISTING METHODS (KEPT AS IS)
    // ========================================

    /**
     * Get all payments for a loan
     */
    public function index(Loan $loan)
    {
        $this->authorize('view', $loan);

        $payments = $loan->payments()
            ->orderBy('id')
            ->paginate(10);

        return response()->json([
            'payments' => $payments,
        ]);
    }

    public function adminIndex(Request $request)
    {
        try {
            $type = $request->query('type', 'all');
            $search = $request->query('search', '');
            $sortColumn = $request->query('sort_column', 'id');
            $sortOrder = $request->query('sort_order', 'desc');
            $perPage = (int) $request->query('per_page', 10);

            // Allowed columns for sorting to prevent SQL injection
            $allowedSorts = ['id', 'due_date', 'amount', 'loan_id', 'status'];
            if (! in_array($sortColumn, $allowedSorts)) {
                $sortColumn = 'id';
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
            $query->orderBy($sortColumn, $sortOrder)->orderBy('id', 'desc');

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
    public function store(Request $request, ?Loan $loan = null)
    {
        // If loan is provided (from route), use existing logic
        if ($loan) {
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

        // New logic for borrower submitting payment with proof
        $request->validate([
            'loan_id' => 'required|exists:loans,id',
            'amount' => 'required|numeric|min:0',
            'payment_method' => 'required|string',
            'payment_date' => 'required|date',
            'proof_of_payment' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'notes' => 'nullable|string|max:500',
        ]);

        $user = $request->user();

        // Verify the loan belongs to this borrower
        $loan = Loan::findOrFail($request->loan_id);
        if ($loan->borrower_id !== $user->id) {
            return response()->json([
                'message' => 'Unauthorized to submit payment for this loan',
            ], 403);
        }

        // Store proof of payment
        $proofPath = null;
        if ($request->hasFile('proof_of_payment')) {
            $proofPath = $request->file('proof_of_payment')
                ->store('payment-proofs', 'public');
        }

        $payment = Payment::create([
            'loan_id' => $request->loan_id,
            'amount' => $request->amount,
            'payment_method' => $request->payment_method,
            'payment_date' => $request->payment_date,
            'proof_of_payment' => $proofPath,
            'notes' => $request->notes,
            'status' => 'awaiting_verification',
            'transaction_id' => 'TXN-'.strtoupper(uniqid()),
            'due_date' => $request->due_date ?? now(),
        ]);

        return response()->json([
            'message' => 'Payment submitted successfully',
            'payment' => $payment->load('loan'),
        ], 201);
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

                $payment = Payment::create($paymentData);

                // Handle proof_of_payment upload
                $file = $request->file('proof_of_payment');
                $folder = public_path("payment_proofs/{$payment->id}");

                if (! File::exists($folder)) {
                    File::makeDirectory($folder, 0755, true); // recursive & writable
                }

                $extension = $file->getClientOriginalExtension();
                $fileName = 'payment_proof.'.$extension;

                // Move file to public/payment_proofs/
                $file->move($folder, $fileName);

                // Relative path for DB
                $proofPath = "payment_proofs/{$payment->id}/{$fileName}";

                if (in_array('proof_of_payment', $paymentColumns)) {
                    $payment->update(['proof_of_payment' => $proofPath]);
                }

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
        $this->authorize('view', $payment);

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

    public function downloadProof(Payment $payment)
    {
        $user = auth()->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated',
            ], 401);
        }

        // Check if file exists
        $filePath = public_path($payment->proof_of_payment);
        if (! file_exists($filePath)) {
            abort(404, 'File not found');
        }

        $downloadName = 'payment_'.$payment->id.'.'.pathinfo($filePath, PATHINFO_EXTENSION);

        return response()->download($filePath, $downloadName);
    }
}
