<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use App\Models\User;

class SettingsController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();

        Log::info('Fetching users', [
            'user_id' => $user->id,
            'role' => $user->role,
        ]);

        return response()->json([
            'data' => [
                'id' => $request->user()->id,
                'first_name' => $request->user()->first_name,
                'last_name' => $request->user()->last_name,
                'email' => $request->user()->email,
                'phone' => $request->user()->phone,
                'profile_url' => $request->user()->profile_url
                    ? asset($request->user()->profile_url)
                    : null,
                'address' => $request->user()->address,
                'city' => $request->user()->city,
                'state' => $request->user()->state,
                'postal_code' => $request->user()->postal_code,
                'country' => $request->user()->country,
            ]
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        // ---------- Handle profile image upload ----------
        if ($request->hasFile('profile_url')) {
            $validator = Validator::make($request->all(), [
                'profile_url' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120', // Added webp
            ], [
                'profile_url.image' => 'The uploaded file must be a valid image.',
                'profile_url.mimes' => 'The image must be a file of type: jpeg, png, jpg, gif, or webp.',
                'profile_url.max' => 'The image size must not exceed 5MB.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $file = $request->file('profile_url');

            // Additional validation: check if file is actually an image
            $imageInfo = @getimagesize($file->getPathname());
            if ($imageInfo === false) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => [
                        'profile_url' => ['The uploaded file is not a valid image.']
                    ]
                ], 422);
            }

            // Validate image dimensions (optional - you can remove this if not needed)
            // $maxWidth = 4000;
            // $maxHeight = 4000;
            // if ($imageInfo[0] > $maxWidth || $imageInfo[1] > $maxHeight) {
            //     return response()->json([
            //         'message' => 'Validation failed',
            //         'errors' => [
            //             'profile_url' => ["Image dimensions must not exceed {$maxWidth}x{$maxHeight} pixels."]
            //         ]
            //     ], 422);
            // }

            // Delete old image if exists
            if ($user->profile_url) {
                $oldFilePath = public_path($user->profile_url);
                if (file_exists($oldFilePath)) {
                    unlink($oldFilePath);
                }
            }

            // Store new image directly in public/profiles
            $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $file->move(public_path('profiles'), $filename);

            $user->profile_url = 'profiles/' . $filename;
            $user->save();

            return response()->json([
                'message' => 'Profile image updated successfully',
                'data' => [
                    'profile_url' => asset($user->profile_url) // Return public URL
                ]
            ]);
        }

        // ---------- Handle profile image removal ----------
        if ($request->input('remove_profile_url')) {
            if ($user->profile_url) {
                $oldFilePath = public_path($user->profile_url);
                if (file_exists($oldFilePath)) {
                    unlink($oldFilePath);
                }
                $user->profile_url = null;
                $user->save();
            }

            return response()->json([
                'message' => 'Profile image removed successfully',
                'data' => [
                    'profile_url' => null
                ]
            ]);
        }

        // ---------- Handle name update ----------
        $validator = Validator::make($request->all(), [
            'first_name' => 'sometimes|required|string|max:255',
            'last_name' => 'sometimes|required|string|max:255',
            'address' => 'sometimes|required|string|max:255',
            'city' => 'sometimes|required|string|max:255',
            'state' => 'sometimes|required|string|max:255',
            'postal_code' => 'sometimes|required|string|max:255',
            'country' => 'sometimes|required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user->update($request->only(['first_name', 'last_name', 'address', 'city', 'state', 'postal_code', 'country']));

        return response()->json([
            'message' => 'Profile updated successfully',
            'data' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'profile_url' => $user->profile_url ? asset($user->profile_url) : null,
                'phone' => $user->phone,
                'address' => $user->address,
                'city' => $user->city,
                'state' => $user->state,
                'postal_code' => $user->postal_code,
                'country' => $user->country,
            ]
        ]);
    }


    public function updateContact(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|unique:users,email,' . $request->user()->id,
            'phone' => 'required|regex:/^[0-9]{11}$/',
        ], [
            'email.unique' => 'This email address is already in use.',
            'phone.regex' => 'Phone number must be exactly 11 digits.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $request->user()->update($request->only(['email', 'phone']));

        return response()->json([
            'message' => 'Contact information updated successfully',
            'data' => $request->user()
        ]);
    }

    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required',
            'new_password' => [
                'required',
                'string',
                'min:8',
                'regex:/[a-z]/',      // at least one lowercase
                'regex:/[A-Z]/',      // at least one uppercase
                'regex:/[0-9]/',      // at least one digit
                'regex:/[@$!%*#?&]/', // at least one special char
                'confirmed'
            ],
        ], [
            'new_password.min' => 'Password must be at least 8 characters.',
            'new_password.regex' => 'Password must contain at least one uppercase letter, one lowercase letter, one number, and one special character.',
            'new_password.confirmed' => 'Password confirmation does not match.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        // Verify current password
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect'
            ], 422);
        }

        // Check if new password is different
        if (Hash::check($request->new_password, $user->password)) {
            return response()->json([
                'message' => 'New password must be different from current password'
            ], 422);
        }

        // Update password
        $user->password = Hash::make($request->new_password);
        $user->save();

        return response()->json([
            'message' => 'Password changed successfully'
        ]);
    }
}