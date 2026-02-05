<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLoanRequest;
use App\Models\Loan;
use App\Models\LoanDocument;
use App\Models\User;
use App\Services\LoanService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LoanController extends Controller
{
    public function __construct(
        private LoanService $loanService
    ) {
        $this->middleware('auth:sanctum');
    }

    /**
     * Create a new loan application
     */
    public function store(StoreLoanRequest $request)
    {
        try {
            /** @var User $user */
            $user = auth()->user();

            DB::beginTransaction();

            // Create the loan application
            $loan = $this->loanService->createLoanApplication(
                borrowerId: $user->id,
                type: $request->input('type'),
                principalAmount: (float) $request->input('principal_amount'),
                interestRate: (float) $request->input('interest_rate'),
                termMonths: (int) $request->input('term_months'),
                purpose: $request->input('purpose'),
                employmentStatus: $request->input('employment_status'),
            );

            // Handle document uploads
            if ($request->has('documents')) {
                foreach ($request->input('documents') as $index => $documentData) {
                    $file = $request->file("documents.$index.file");
                    $documentType = $documentData['type'];

                    if ($file && $file->isValid()) {
                        // Get file information BEFORE moving
                        $originalName = $file->getClientOriginalName();
                        $extension = $file->getClientOriginalExtension();
                        $fileSize = $file->getSize();
                        $mimeType = $file->getMimeType();

                        // Generate unique filename
                        $filename = Str::slug(pathinfo($originalName, PATHINFO_FILENAME))
                            .'_'.time()
                            .'_'.Str::random(8)
                            .'.'.$extension;

                        // Move file to public path
                        $destinationPath = public_path('documents/loans/'.$loan->id);

                        // Create directory if it doesn't exist
                        if (! file_exists($destinationPath)) {
                            mkdir($destinationPath, 0755, true);
                        }

                        // Move the file
                        $file->move($destinationPath, $filename);

                        // Store relative path for database
                        $filePath = 'documents/loans/'.$loan->id.'/'.$filename;

                        // Create document record using the information we saved BEFORE moving
                        LoanDocument::create([
                            'loan_id' => $loan->id,
                            'document_type' => $documentType,
                            'file_path' => $filePath,
                            'file_name' => $originalName,
                            'file_size' => $fileSize,
                            'mime_type' => $mimeType,
                            'uploaded_by' => $user->id,
                        ]);

                        Log::info('Document uploaded successfully', [
                            'loan_id' => $loan->id,
                            'document_type' => $documentType,
                            'filename' => $filename,
                            'size' => $fileSize,
                        ]);
                    }
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Loan application submitted successfully',
                'loan' => $loan->load(['borrower', 'documents']),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Loan application submission failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);

            return response()->json([
                'message' => 'Failed to submit loan application',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred',
            ], 500);
        }
    }

    /**
     * Get all loans for authenticated user
     * FIXED: Return loans as array instead of paginated object
     */
    public function index(Request $request)
    {
        try {
            /** @var User $user */
            $user = auth()->user();

            Log::info('Fetching loans for user', [
                'user_id' => $user->id,
                'role' => $user->role,
            ]);

            $query = null;

            if ($user->isAdmin()) {
                $query = Loan::with(['borrower', 'lender', 'loanOfficer', 'documents']);
            } elseif ($user->isLender()) {
                $query = Loan::with(['borrower', 'lender', 'loanOfficer', 'documents'])
                    ->where(function ($q) use ($user) {
                        // 1. All loans assigned to this loan officer (any status)
                        $q->where('lender_id', $user->id)
                          // 2. All unassigned pending loans
                            ->orWhere(function ($q2) {
                                $q2->where('status', 'pending');
                            });
                    });

            } elseif ($user->isLoanOfficer()) {
                $query = Loan::with(['borrower', 'lender', 'loanOfficer', 'documents'])
                    ->where(function ($q) use ($user) {
                        // 1. All loans assigned to this loan officer (any status)
                        $q->where('loan_officer_id', $user->id)
                          // 2. All unassigned pending loans
                            ->orWhere(function ($q2) {
                                $q2->whereNull('loan_officer_id')
                                    ->where('status', 'pending');
                            });
                    });
            } else {
                // Borrower - show only their loans
                $query = Loan::where('borrower_id', $user->id)
                    ->with(['lender', 'loanOfficer', 'documents']);
            }

            // Get all loans without pagination for simplicity
            $loans = $query->orderBy('created_at', 'desc')->get();

            Log::info('Loans fetched successfully', [
                'count' => $loans->count(),
                'user_id' => $user->id,
            ]);

            return response()->json([
                'loans' => $loans,
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching loans', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to fetch loans',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred',
                'loans' => [], // Return empty array so frontend doesn't break
            ], 500);
        }
    }

    /**
     * Get single loan details
     */
    public function show(Loan $loan)
    {
        $this->authorize('view', $loan);

        // FIXED: Don't load payments relationship to avoid deleted_at error
        return response()->json([
            'loan' => $loan->load(['borrower', 'lender', 'loanOfficer', 'documents']),
        ]);
    }

    /**
     * Update loan (admin/lender only)
     */
    public function update(Request $request, Loan $loan)
    {
        $this->authorize('update', $loan);
        Log::info('Activate loan payload', $request->all());

        $validated = $request->validate([
            'status' => 'sometimes|in:pending,approved,rejected,active,completed,defaulted',
            'approved_amount' => 'sometimes|numeric',
            'interest_rate' => 'sometimes|numeric',
            'notes' => 'sometimes|string',
            'rejection_reason' => 'sometimes|string',
            'lender_id' => 'sometimes|exists:users,id',
            'start_date' => 'sometimes|date',
            'first_payment_date' => 'sometimes|date',
        ]);

        /** @var User $user */
        $user = auth()->user();

        if (isset($validated['status'])) {
            if ($validated['status'] === 'approved') {
                $validated['approved_at'] = now();

                // Assign lender
                if ($user->isAdmin()) {
                    // Use the lender from the form if provided
                    if (isset($validated['lender_id'])) {
                        $loan->lender_id = $validated['lender_id'];
                    }
                } elseif ($user->isLender()) {
                    // If the approver is the lender itself, assign self
                    $validated['lender_id'] = $user->id;
                }
            }

            if ($validated['status'] === 'rejected') {
                $validated['rejected_at'] = now();
            }
        }

        $loan->update($validated);

        return response()->json([
            'message' => 'Loan updated successfully',
            'loan' => $loan,
        ]);
    }

    /**
     * Approve loan application
     */
    public function approve(Request $request, Loan $loan)
    {
        $this->authorize('approve', $loan);

        $validated = $request->validate([
            'approved_amount' => 'required|numeric|min:0',
            'interest_rate' => 'sometimes|numeric|min:0|max:100',
            'lender_id' => 'sometimes|exists:users,id',
        ]);

        /** @var User $user */
        $user = auth()->user();

        // Use the lender_id from the request if provided, otherwise fallback to the authenticated user
        $lenderId = $validated['lender_id'] ?? $user->id;

        $loan = $this->loanService->approveLoan(
            loan: $loan,
            approvedAmount: (float) $validated['approved_amount'],
            lenderId: $lenderId,
            loanOfficerId: $user->id,
            interestRate: isset($validated['interest_rate']) ? (float) $validated['interest_rate'] : null,
        );

        return response()->json([
            'message' => 'Loan approved successfully',
            'loan' => $loan->load(['borrower', 'lender', 'loanOfficer']),
        ]);
    }

    /**
     * Reject loan application
     */
    public function reject(Request $request, Loan $loan)
    {
        $this->authorize('reject', $loan);

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $loan = $this->loanService->rejectLoan($loan, $validated['reason']);

        return response()->json([
            'message' => 'Loan rejected',
            'loan' => $loan,
        ]);
    }

    /**
     * Activate approved loan
     */
    public function activate(Request $request, Loan $loan)
    {
        try {
            // 1. Authorize
            $this->authorize('activate', $loan);

            // 2. Only approved loans can be activated
            if ($loan->status !== 'approved') {
                return response()->json([
                    'message' => 'Only approved loans can be activated',
                ], 422);
            }

            // 3. Validate input
            $validated = $request->validate([
                'start_date' => 'required|date',
                'first_payment_date' => 'required|date|after_or_equal:start_date',
                'notes' => 'nullable|string',
            ]);

            $startDate = Carbon::parse($validated['start_date']);
            $firstPaymentDate = Carbon::parse($validated['first_payment_date']);

            // 5. Activate loan via service
            $loan = $this->loanService->activateLoan(
                loan: $loan,
                startDate: $startDate,
                firstPaymentDate: $firstPaymentDate,
                notes: $validated['notes'] ?? null
            );

            // 6. Return success with related data
            return response()->json([
                'message' => 'Loan activated successfully. Payment schedule has been generated.',
                'loan' => $loan->load(['borrower', 'lender', 'loanOfficer', 'payments']),
            ]);

        } catch (\Exception $e) {
            Log::error('Error activating loan', [
                'loan_id' => $loan->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to activate loan',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get loan statistics
     */
    public function statistics(Request $request)
    {
        /** @var User $user */
        $user = auth()->user();
        $stats = $this->loanService->getUserStatistics($user->id, $user->role);

        return response()->json($stats);
    }

    /**
     * Download loan document
     */
    public function downloadDocument(Loan $loan, LoanDocument $document)
    {
        $this->authorize('view', $loan);

        if ($document->loan_id !== $loan->id) {
            abort(404);
        }

        $filePath = public_path($document->file_path);

        if (! file_exists($filePath)) {
            abort(404, 'File not found');
        }

        return response()->download($filePath, $document->file_name);
    }
}
