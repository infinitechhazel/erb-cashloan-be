<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BorrowerController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * List all borrowers (admins only, or filtered for other roles)
     */
    public function index()
    {
        // Comment out policy check for now
        // $this->authorize('viewAny', User::class);

        $user = auth()->user();

        if ($user->isAdmin()) {
            $borrowers = User::with('loans')
                ->where('role', 'borrower')
                ->get();
        } else {
            $borrowers = User::with('loans')
                ->where('id', $user->id)
                ->get();
        }

        return response()->json([
            'users' => $borrowers
        ]);
    }

    /**
     * Show a specific borrower
     */
    public function show(User $borrower)
    {
        $this->authorize('view', $borrower);

        $borrower->load('loans');

        return response()->json($borrower);
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
            'email' => 'sometimes|email|unique:users,email,' . $borrower->id,
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
