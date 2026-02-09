<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class LenderController extends Controller
{
    public function index(Request $request)
    {

        /** @var User $user */
        $user = auth()->user();

        Log::info('Fetching users', [
            'user_id' => $user->id,
            'role' => $user->role,
        ]);


        // Only admin can access
        if ($user->role !== 'admin') {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        $query = User::where('role', 'lender');

        // Optional search query (?q=searchTerm)
        if ($request->has('q')) {
            $search = $request->get('q');
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $lenders = $query->select('id', 'first_name', 'last_name', 'email')->get();

        // Combine first + last name for frontend
        $lenders = $lenders->map(function ($lender) {
            return [
                'id' => $lender->id,
                'name' => $lender->first_name . ' ' . $lender->last_name,
                'email' => $lender->email,
            ];
        });

        return response()->json([
            'lenders' => $lenders
        ]);
    }
}
