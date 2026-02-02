<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;

class LoanOfficerController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // Only admin can access
        if ($user->role !== 'admin') {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        $query = User::where('role', 'loan_officer');

        // Search query
        if ($request->has('q')) {
            $search = $request->get('q');
            $query->where(function($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $loanOfficers = $query->select('id', 'first_name', 'last_name', 'email')->get();

        // Combine first + last name for frontend
        $loanOfficers = $loanOfficers->map(function($officer) {
            return [
                'id' => $officer->id,
                'name' => $officer->first_name . ' ' . $officer->last_name,
                'email' => $officer->email,
            ];
        });

        return response()->json([
            'loan_officers' => $loanOfficers
        ]);
    }
}
