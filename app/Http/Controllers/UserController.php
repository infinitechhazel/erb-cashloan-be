<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    /**
     * Get all users with pagination, search, sorting, and filtering
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);
            $search = $request->input('search', '');
            $role = $request->input('role', 'all');
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

            // Apply role filter
            if ($role && $role !== 'all') {
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
                    'name' => $user->first_name.' '.$user->last_name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'profile_url' => $user->profile_picture,
                    'is_active' => $user->is_active,
                    'role' => $user->role,
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
     * Get a single user by ID
     */
    public function show(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);
            Log::info('Fetching profile for user ID: '.$request->user()->id);
            $user = User::find($request->user()->id);

            Log::info('Fetching users', [
                'user_id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'role' => $user->role,
                'phone' => $user->phone,
                'profile_url' => $user->profile_url ?? null,
                'address' => $user->address,
                'city' => $user->city,
                'state' => $user->state,
                'postal_code' => $user->postal_code,
                'country' => $user->country,
                'created_at' => $user->created_at->toISOString(),
            ]);

            return response()->json([
                'user' => [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'phone' => $user->phone,
                    'profile_url' => $user->profile_url ?? null,
                    'address' => $user->address,
                    'city' => $user->city,
                    'state' => $user->state,
                    'postal_code' => $user->postal_code,
                    'country' => $user->country,
                    'created_at' => $user->created_at->toISOString(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'User not found',
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Update user role or is_active fields
     */
    public function update(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);

            // Validate incoming request
            $validated = $request->validate([
                'role' => 'sometimes|string|in:admin,lender,borrower',
                'is_active' => 'sometimes|boolean',
            ]);

            // Update fields if provided
            $updateData = [];
            if (isset($validated['role'])) {
                $updateData['role'] = $validated['role'];
            }
            if (isset($validated['is_active'])) {
                $updateData['is_active'] = $validated['is_active'];
            }

            if (! empty($updateData)) {
                $user->update($updateData);
            }

            return response()->json([
                'message' => 'User updated successfully',
                'user' => $user,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error updating user',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete user
     */
    public function destroy($id)
    {
        try {
            $user = User::findOrFail($id);
            $user->delete();

            return response()->json([
                'message' => 'User deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error deleting user',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
