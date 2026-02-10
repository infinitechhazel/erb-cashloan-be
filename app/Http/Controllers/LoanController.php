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
     * Get all loans for authenticated user
     * FIXED: Return loans as array instead of paginated object
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);
            $search = $request->input('search', '');
            $status = $request->input('status', 'all');
            $sortBy = $request->input('sort_by', 'created_at');
            $sortOrder = $request->input('sort_order', 'desc');

            /** @var User $user */
            $user = auth()->user();

            Log::info('Fetching loans for user', [
                'user_id' => $user->id,
                'role' => $user->role,
            ]);

            $query = null;

            if ($user->isAdmin()) {
                $query = Loan::with(['borrower', 'lender', 'loanOfficer', 'documents']);

                if ($search) {
                    $query->where(function ($q) use ($search) {
                        if (is_numeric($search)) {
                            $q->where('id', $search);
                        } else {
                            $q->where('type', 'like', "%{$search}%")
                                ->orWhere('status', 'like', "%{$search}%")
                                ->orWhereHas('borrower', function ($q2) use ($search) {
                                    $q2->where('first_name', 'like', "%{$search}%")
                                        ->orWhere('last_name', 'like', "%{$search}%")
                                        ->orWhere('email', 'like', "%{$search}%");
                                })
                                ->orWhereHas('lender', function ($q2) use ($search) {
                                    $q2->where('first_name', 'like', "%{$search}%")
                                        ->orWhere('last_name', 'like', "%{$search}%");
                                });
                        }
                    });
                }

                if ($status && $status !== 'all') {
                    $query->where('status', $status);
                }

                $allowedSortFields = ['id', 'type', 'principal_amount', 'status', 'created_at'];
                if (in_array($sortBy, $allowedSortFields)) {
                    $query->orderBy($sortBy, $sortOrder);
                } else {
                    $query->orderBy('created_at', $sortOrder);
                }

            } elseif ($user->isLender()) {
                $query = Loan::with(['borrower', 'lender', 'loanOfficer', 'documents'])
                    ->where(function ($q) use ($user) {
                        $q->whereIn('status', ['pending', 'approved'])
                            ->whereNotNull('lender_id')
                            ->orWhere(function ($q2) use ($user) {
                                $q2->where('lender_id', $user->id)
                                    ->whereIn('status', ['active', 'completed']);
                            });
                    });

                if ($search) {
                    $query->where(function ($q) use ($search) {
                        if (is_numeric($search)) {
                            $q->where('id', $search);
                        } else {
                            $q->where('type', 'like', "%{$search}%")
                                ->orWhere('status', 'like', "%{$search}%")
                                ->orWhereHas('borrower', function ($q2) use ($search) {
                                    $q2->where('first_name', 'like', "%{$search}%")
                                        ->orWhere('last_name', 'like', "%{$search}%")
                                        ->orWhere('email', 'like', "%{$search}%");
                                })
                                ->orWhereHas('lender', function ($q2) use ($search) {
                                    $q2->where('first_name', 'like', "%{$search}%")
                                        ->orWhere('last_name', 'like', "%{$search}%");
                                });
                        }
                    });
                }

                if ($status && $status !== 'all') {
                    $query->where('status', $status);
                }

            } elseif ($user->isLoanOfficer()) {
                $query = Loan::where('loan_officer_id', $user->id)
                    ->with(['borrower', 'lender', 'loanOfficer', 'documents']);

                if ($search) {
                    $query->where(function ($q) use ($search) {
                        if (is_numeric($search)) {
                            $q->where('id', $search);
                        } else {
                            $q->whereHas('borrower', function ($q2) use ($search) {
                                $q2->where('first_name', 'like', "%{$search}%")
                                    ->orWhere('last_name', 'like', "%{$search}%");
                            });
                        }
                    });
                }

                if ($status && $status !== 'all') {
                    $query->where('status', $status);
                }

            } else {
                // Borrower
                $query = Loan::where('borrower_id', $user->id)
                    ->with(['borrower', 'lender', 'loanOfficer', 'documents']);

                if ($search && is_numeric($search)) {
                    $query->where('id', $search);
                }

                if ($status && $status !== 'all') {
                    $query->where('status', $status);
                }
            }

            // Paginate
            $loans = $query->paginate($perPage);

            Log::info('Loans fetched successfully', [
                'count' => $loans->count(),
                'total' => $loans->total(),
                'user_id' => $user->id,
            ]);

            return response()->json([
                'data' => $loans->items(),
                'current_page' => $loans->currentPage(),
                'last_page' => $loans->lastPage(),
                'per_page' => $loans->perPage(),
                'total' => $loans->total(),
                'from' => $loans->firstItem() ?? 0,
                'to' => $loans->lastItem() ?? 0,
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching loans', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'data' => [],
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => $perPage ?? 10,
                'total' => 0,
                'from' => 0,
                'to' => 0,
            ], 200);
        }
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
                            . '_' . date('Y-m-d_H-i-s')
                            . '_' . $loan->id
                            . '.' . $extension;

                        // Create folder structure: documents/loans/{loan_id}/{document_type}
                        $destinationPath = public_path('documents/loans/' . $loan->id . '/' . $documentType);

                        // Create directory if it doesn't exist
                        if (!file_exists($destinationPath)) {
                            mkdir($destinationPath, 0755, true);
                        }

                        // Move the file
                        $file->move($destinationPath, $filename);

                        // Store relative path for database (this is what will be used in frontend)
                        $filePath = '/documents/loans/' . $loan->id . '/' . $documentType . '/' . $filename;

                        // Create document record
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
                            'path' => $filePath,
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
            'notes' => 'sometimes|string|nullable',
        ]);

        $user = auth()->user();
        $lenderId = $validated['lender_id'] ?? $user->id;

        $loan = $this->loanService->approveLoan(
            loan: $loan,
            approvedAmount: (float) $validated['approved_amount'],
            lenderId: $lenderId,
            loanOfficerId: $user->id,
            interestRate: isset($validated['interest_rate']) ? (float) $validated['interest_rate'] : null,
        );

        if (isset($validated['notes'])) {
            $loan->notes = $validated['notes'];
            $loan->save();
        }

        return response()->json([
            'message' => 'Loan approved successfully',
            'loan' => $loan->fresh()->load(['borrower', 'lender', 'loanOfficer']),
        ]);
    }

    /**
     * Reject loan application
     */
    public function reject(Request $request, Loan $loan)
    {
        $this->authorize('reject', $loan);

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
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

        $loan->status = 'active';
        $loan->start_date = $request->input('start_date');
        $loan->disbursement_date = $request->input('first_payment_date');
        $loan->notes = $request->input('notes', $loan->notes);

        // Assign lender_id only if the user is a lender
        if ($currentUser->isLender()) {
            $loan->lender_id = $currentUser->id;
        }

        $loan->save();

        return response()->json([
            'message' => 'Loan activated successfully',
            'loan' => $loan,
        ]);
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
     * List documents for a loan
     */
    public function indexDocument(Loan $loan)
    {
        $user = auth()->user();

        Log::info('Document authorization check', [
            'loan_id' => $loan->id,
            'loan_borrower_id' => $loan->borrower_id,
            'loan_lender_id' => $loan->lender_id,
            'auth_user_id' => $user->id,
            'auth_user_role' => $user->role,
            'is_admin' => $user->isAdmin(),
            'is_borrower' => $user->isBorrower(),
            'is_lender' => $user->isLender(),
        ]);

        // This will throw an exception with details if it fails
        $this->authorize('viewDocuments', $loan);

        $documents = LoanDocument::where('loan_id', $loan->id)->get();

        return response()->json([
            'success' => true,
            'documents' => $documents,
            'count' => $documents->count()
        ]);
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

        if (!file_exists($filePath)) {
            abort(404, 'File not found');
        }

        return response()->download($filePath, $document->file_name);
    }

    public function getWalletInfo(Request $request, $loanId)
    {
        try {
            $loan = Loan::with(['lender', 'borrower'])->findOrFail($loanId);
            $user = $request->user();

            // DEBUG: Log all the values
            Log::info('Wallet Info Authorization Debug', [
                'loan_id' => $loanId,
                'current_user_id' => $user->id,
                'user_role' => $user->role,
                'loan_borrower_id' => $loan->borrower_id,
                'loan_lender_id' => $loan->lender_id,
                'loan_status' => $loan->status,
            ]);

            // CHANGED: Check user role instead of specific loan relationship
            $canView = $user->isAdmin() || $user->isBorrower() || $user->isLender();

            // DEBUG: Log the check results
            Log::info('Authorization Check Results', [
                'user_role' => $user->role,
                'is_admin' => $user->isAdmin(),
                'is_borrower' => $user->isBorrower(),
                'is_lender' => $user->isLender(),
                'can_view' => $canView,
            ]);

            // Allow any user with borrower, lender, or admin role to view wallet info
            if (!$canView) {
                Log::warning('Unauthorized wallet access attempt', [
                    'user_id' => $user->id,
                    'user_role' => $user->role,
                    'loan_id' => $loanId,
                    'reason' => 'User role is not borrower, lender, or admin',
                ]);

                return response()->json([
                    'message' => 'Unauthorized to view this wallet information',
                    'debug' => config('app.debug') ? [
                        'your_user_id' => $user->id,
                        'your_role' => $user->role,
                    ] : null
                ], 403);
            }

            // Check if wallet information exists
            if (!$loan->receiver_wallet_name && !$loan->receiver_wallet_number) {
                return response()->json([
                    'message' => 'Wallet information has not been added yet by the lender',
                    'has_wallet_info' => false
                ], 404);
            }

            // Build wallet data response
            $walletData = [
                'wallet_name' => $loan->receiver_wallet_name,
                'wallet_number' => $loan->receiver_wallet_number,
                'wallet_email' => $loan->receiver_wallet_email,
                'wallet_proof_url' => $loan->receiver_wallet_proof ?? null,
                'has_wallet_info' => true,
            ];

            // Include lender info if available
            if ($loan->lender) {
                $walletData['lender'] = [
                    'id' => $loan->lender->id,
                    'name' => $loan->lender->first_name . ' ' . $loan->lender->last_name,
                    'email' => $loan->lender->email,
                ];
            }

            // Include borrower info for lender/admin
            if ($user->isLender() || $user->isAdmin()) {
                if ($loan->borrower) {
                    $walletData['borrower'] = [
                        'id' => $loan->borrower->id,
                        'name' => $loan->borrower->first_name . ' ' . $loan->borrower->last_name,
                        'email' => $loan->borrower->email,
                    ];
                }
            }

            Log::info('Wallet info retrieved successfully', [
                'user_id' => $user->id,
                'user_role' => $user->role,
                'loan_id' => $loanId,
            ]);

            return response()->json([
                'success' => true,
                'wallet' => $walletData,
                'loan_status' => $loan->status,
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Loan not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve wallet information', [
                'loan_id' => $loanId,
                'user_id' => $request->user()->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to retrieve wallet information',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred'
            ], 500);
        }
    }

    public function updateWalletInfo(Request $request, $loanId)
    {
        try {
            $loan = Loan::findOrFail($loanId);

            // Only lender or admin can update wallet info
            if ($request->user()->id !== $loan->lender_id && !$request->user()->is_admin) {
                return response()->json([
                    'message' => 'Unauthorized to update wallet information'
                ], 403);
            }

            // Log incoming request for debugging
            Log::info('Wallet update request received', [
                'loan_id' => $loanId,
                'has_file' => $request->hasFile('receiver_wallet_proof'),
                'all_data' => $request->except(['receiver_wallet_proof']),
            ]);

            // Validate the request - changed 'image' to 'file' for better compatibility
            $validated = $request->validate([
                'receiver_wallet_name' => 'required|string|max:255',
                'receiver_wallet_number' => 'required|string|max:100',
                'receiver_wallet_email' => 'nullable|email|max:255',
                'receiver_wallet_proof' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
            ]);

            // Handle file upload
            if ($request->hasFile('receiver_wallet_proof')) {
                $file = $request->file('receiver_wallet_proof');

                if ($file->isValid()) {
                    // Delete old proof if exists
                    if ($loan->receiver_wallet_proof) {
                        $oldFilePath = public_path($loan->receiver_wallet_proof);
                        if (file_exists($oldFilePath)) {
                            unlink($oldFilePath);
                            Log::info('Old wallet proof deleted', [
                                'loan_id' => $loan->id,
                                'old_path' => $loan->receiver_wallet_proof
                            ]);
                        }
                    }

                    // Get file information BEFORE moving
                    $originalName = $file->getClientOriginalName();
                    $extension = $file->getClientOriginalExtension();
                    $fileSize = $file->getSize();
                    $mimeType = $file->getMimeType();

                    // Generate unique filename
                    $filename = Str::slug(pathinfo($originalName, PATHINFO_FILENAME))
                        . '_' . date('Y-m-d_H-i-s')
                        . '_' . $loan->id
                        . '.' . $extension;

                    // Create folder structure: documents/loans/{loan_id}/wallet-proofs
                    $destinationPath = public_path('documents/loans/' . $loan->id . '/wallet-proofs');

                    // Create directory if it doesn't exist
                    if (!file_exists($destinationPath)) {
                        mkdir($destinationPath, 0755, true);
                    }

                    // Move the file
                    $file->move($destinationPath, $filename);

                    // Store relative path for database (this is what will be used in frontend)
                    $filePath = '/documents/loans/' . $loan->id . '/wallet-proofs/' . $filename;

                    $validated['receiver_wallet_proof'] = $filePath;

                    Log::info('Wallet proof uploaded successfully', [
                        'loan_id' => $loan->id,
                        'filename' => $filename,
                        'path' => $filePath,
                        'size' => $fileSize,
                        'original_name' => $originalName,
                    ]);
                } else {
                    Log::warning('Invalid file upload', [
                        'loan_id' => $loan->id,
                        'error' => $file->getErrorMessage()
                    ]);
                }
            }

            // Update only the validated fields
            $loan->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Wallet information updated successfully',
                'wallet' => [
                    'wallet_name' => $loan->receiver_wallet_name,
                    'wallet_number' => $loan->receiver_wallet_number,
                    'wallet_email' => $loan->receiver_wallet_email,
                    'wallet_proof_url' => $loan->receiver_wallet_proof ?? null,
                ]
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation failed', [
                'loan_id' => $loanId,
                'errors' => $e->errors(),
                'input' => $request->except(['receiver_wallet_proof'])
            ]);

            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to update wallet information', [
                'loan_id' => $loanId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to update wallet information',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}