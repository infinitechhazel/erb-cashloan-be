<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Register a new user
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => 'borrower',
            'is_active' => true,
        ]);

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'User registered successfully',
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    /**
     * Login user
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Update last login
        $user->update(['last_login_at' => now()]);

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'user' => $user,
            'token' => $token,
        ]);
    }

    /**
     * Get authenticated user
     */
    public function me(Request $request)
    {
        return response()->json([
            'user' => $request->user(),
        ]);
    }

    /**
     * Logout user
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Verify email
     */
    public function verifyEmail(Request $request)
    {
        $request->user()->markEmailAsVerified();

        return response()->json([
            'message' => 'Email verified successfully',
        ]);
    }

    /**
     * Resend email verification
     */
    public function resendEmailVerification(Request $request)
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email already verified',
            ], 400);
        }

        // Send verification email
        $request->user()->sendEmailVerificationNotification();

        return response()->json([
            'message' => 'Verification email sent',
        ]);
    }

    /**
     * Change password
     */
    public function changePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => 'required',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if (!Hash::check($validated['current_password'], $request->user()->password)) {
            return response()->json([
                'message' => 'Current password is incorrect',
            ], 422);
        }

        $request->user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        return response()->json([
            'message' => 'Password changed successfully',
        ]);
    }

    /**
     * Forgot password - send reset link
     */
    public function forgotPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email|exists:users',
        ]);

        // TODO: Send password reset email
        // Password::sendResetLink(['email' => $validated['email']]);

        return response()->json([
            'message' => 'Password reset link sent to your email',
        ]);
    }

    /**
     * Reset password with token
     */
    public function resetPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email|exists:users',
            'token' => 'required',
            'password' => 'required|string|min:8|confirmed',
        ]);

        // TODO: Verify reset token and update password
        // $status = Password::reset(
        //     $request->only('email', 'password', 'password_confirmation', 'token'),
        //     callback: function ($user, $password) {
        //         $user->forceFill(['password' => Hash::make($password)])->save();
        //     }
        // );

        return response()->json([
            'message' => 'Password reset successful',
        ]);
    }

    /**
     * Get user profile with loans
     */
    public function profile(Request $request)
    {
        $user = $request->user();

        $loans = $user->loans()->orderBy('created_at', 'desc')->get();

        return response()->json([
            'user' => $user,
            'loans' => $loans,
        ]);
    }
}
