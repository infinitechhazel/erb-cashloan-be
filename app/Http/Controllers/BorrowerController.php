<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

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
        try {
            $perPage = $request->input('per_page', 10);
            $search = $request->input('search', '');
            $status = $request->input('status', 'all');
            $sortBy = $request->input('sort_by', 'created_at');
            $sortOrder = $request->input('sort_order', 'desc');

            $user = auth()->user();

            Log::info('Fetching users', [
                'user_id' => $user->id,
                'role' => $user->role,
            ]);

            // Start query
            $query = User::query();

            // Apply search
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            }

            // Apply status filter
            if ($status && $status !== 'all') {
                $query->where('status', $status);
            }

            // Apply sorting
            $allowedSortFields = ['id', 'first_name', 'last_name', 'email', 'created_at'];
            if (in_array($sortBy, $allowedSortFields)) {
                $query->orderBy($sortBy, $sortOrder);
            } else {
                $query->orderBy('created_at', $sortOrder);
            }

            // Paginate
            $users = $query->paginate($perPage);

            // Transform the data to match frontend expectations
            $transformedData = $users->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->first_name . ' ' . $user->last_name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'profile_url' => $user->profile_picture,
                    'status' => $user->status,
                    'created_at' => $user->created_at,
                ];
            });

            Log::info('Users fetched successfully', [
                'count' => $users->count(),
                'total' => $users->total(),
            ]);

            return response()->json([
                'data' => $transformedData,
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'from' => $users->firstItem() ?? 0,
                'to' => $users->lastItem() ?? 0,
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching users', [
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
     * Show a specific borrower with all loans and payments
     */
    public function show(User $borrower)
    {
        $currentUser = auth()->user();

        // Only admins and lenders can view borrower
        if (!in_array($currentUser->role, ['admin', 'lender'])) {
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
                    'created_at' => $loan->created_at,
                    'updated_at' => $loan->updated_at,
                    // Payments for this loan
                    'payments' => $loan->payments->map(function ($payment) {
                        return [
                            'id' => $payment->id,
                            'transaction_id' => $payment->transaction_id,
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
