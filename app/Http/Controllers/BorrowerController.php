<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class BorrowerController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * List all borrowers (admins and lenders)
     */
    public function index(Request $request)
    {

        $currentUser = auth()->user();
        // Only admin and lender can view borrower
        if (! in_array($currentUser->role, ['admin', 'lender'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $query = User::query()->where('role', 'borrower');

        // Global search
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Sorting
        if ($sortBy = $request->query('sortBy')) {
            $sortDir = $request->query('sortDir', 'asc');
            $query->orderBy($sortBy, $sortDir);
        } else {
            $query->orderBy('id', 'desc'); // default sort
        }

        // Pagination
        $page = (int) $request->query('page', 1);
        $pageSize = (int) $request->query('pageSize', 10);

        $total = $query->count();
        $borrowers = $query->skip(($page - 1) * $pageSize)->take($pageSize)->get([
            'id',
            'first_name',
            'last_name',
            'email',
        ]);

        return response()->json([
            'data' => $borrowers,
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
        ]);
    }

    /**
     * Show a specific borrower with all loans and payments
     */
    public function show(User $borrower)
    {
        $currentUser = auth()->user();

        // Only admins and lenders can view borrower
        if (! in_array($currentUser->role, ['admin', 'lender'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Eager load loans and their payments to avoid N+1 queries
        $borrower->load('loans.payments');

        // Map borrower data
        $data = [
            'id' => $borrower->id,
            'first_name' => $borrower->first_name,
            'last_name' => $borrower->last_name,
            'email' => $borrower->email,
            'loans' => $borrower->loans->map(function ($loan) {
                return [
                    'id' => $loan->id,
                    'loan_number' => $loan->loan_number ?? null,
                    'type' => $loan->type,
                    'principal_amount' => $loan->principal_amount,
                    'approved_amount' => $loan->approved_amount,
                    'interest_rate' => $loan->interest_rate,
                    'term_months' => $loan->term_months,
                    'status' => $loan->status,
                    'notes' => $loan->notes,
                    'rejection_reason' => $loan->rejection_reason,
                    'start_date' => $loan->start_date,
                    'first_payment_date' => $loan->first_payment_date,
                    'outstanding_balance' => $loan->outstanding_balance,
                    'documents' => $loan->documents,
                    'created_at' => $loan->created_at,
                    'updated_at' => $loan->updated_at,
                    // Payments for this loan
                    'payments' => $loan->payments->map(function ($payment) {
                        return [
                            'id' => $payment->id,
                            'transaction_id' => $payment->transaction_id,
                            'proof_of_payment' => $payment->proof_of_payment,
                            'amount' => $payment->amount,
                            'status' => $payment->status,
                            'due_date' => $payment->due_date,
                            'paid_at' => $payment->paid_at,
                            'created_at' => $payment->created_at,
                            'updated_at' => $payment->updated_at,
                        ];
                    }),
                ];
            }),
        ];

        return response()->json([
            'borrower' => $data,
        ]);
    }

    /**
     * Create a new borrower (admin only)
     */
    public function store(Request $request)
    {
        $this->authorize('create', User::class);

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $borrower = User::create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'password' => bcrypt($validated['password']),
            'role' => 'borrower',
        ]);

        return response()->json([
            'message' => 'Borrower created successfully',
            'borrower' => $borrower,
        ], 201);
    }

    /**
     * Update borrower info
     */
    public function update(Request $request, User $borrower)
    {
        $this->authorize('update', $borrower);

        $validated = $request->validate([
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,'.$borrower->id,
            'password' => 'nullable|string|min:6|confirmed',
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = bcrypt($validated['password']);
        }

        $borrower->update($validated);

        return response()->json([
            'message' => 'Borrower updated successfully',
            'borrower' => $borrower,
        ]);
    }

    /**
     * Delete a borrower (admin only)
     */
    public function destroy(User $borrower)
    {
        $this->authorize('delete', $borrower);

        $borrower->delete();

        return response()->json([
            'message' => 'Borrower deleted successfully',
        ]);
    }
}
