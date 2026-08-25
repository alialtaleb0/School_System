<?php

namespace App\Http\Controllers;
use App\Models\User;
use App\Services\VerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
    public function __construct(
        private VerificationService $verificationService
    ) {}

    public function register(Request $request)
    {
        $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'address' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'role' => 'nullable|in:student,parent,teacher,admin',
        ]);

        $user = User::create([
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'address' => $request->address,
            'phone' => $request->phone,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role ?? 'student',
        ]);

        $verificationCode = $this->verificationService->generateCode($user);
        $code = $this->verificationService->sendCode($user, $verificationCode);

        return response()->json([
            'message' => __('User Registered successfully. Please check your email for verification code.'),
            'data' => $user,
            'debug_code' => $code,
        ], 201);
    }

    public function verifyEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|string|size:6',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if ($user->email_verified_at) {
            return response()->json(['message' => 'Email is already verified.'], 400);
        }

        $result = $this->verificationService->verify($user, $request->code);

        $statusCode = $result['success'] ? 200 : 422;
        return response()->json(['message' => $result['message']], $statusCode);
    }

    public function resendVerificationCode(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if ($user->email_verified_at) {
            return response()->json(['message' => 'Email is already verified.'], 400);
        }

        $rateCheck = $this->verificationService->canRequestCode($user);

        if (!$rateCheck['allowed']) {
            return response()->json(['message' => $rateCheck['message']], 429);
        }

        $verificationCode = $this->verificationService->generateCode($user);
        $code = $this->verificationService->sendCode($user, $verificationCode);

        return response()->json([
            'message' => 'Verification code sent successfully.',
            'debug_code' => $code,
        ]);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json(['message' => __('Invalid email or password')], 401);
        }

        $user = Auth::user();

        if (!$user->email_verified_at) {
            return response()->json([
                'message' => 'Please verify your email before logging in.',
                'requires_verification' => true,
            ], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => __('Login successfully'),
            'token' => $token,
            'user' => $user,
        ], 200);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'message' => 'If this email exists, a verification code has been sent.',
            ]);
        }

        $rateCheck = $this->verificationService->canRequestCode($user, 'password_reset');

        if (!$rateCheck['allowed']) {
            return response()->json(['message' => $rateCheck['message']], 429);
        }

        $verificationCode = $this->verificationService->generateCode($user, 'password_reset');
        $code = $this->verificationService->sendCode($user, $verificationCode, 'Password Reset Code');

        return response()->json([
            'message' => 'If this email exists, a verification code has been sent.',
            'debug_code' => $code,
        ]);
    }

    public function verifyResetCode(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|string|size:6',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $result = $this->verificationService->verify($user, $request->code, 'password_reset');

        if (!$result['success']) {
            return response()->json(['message' => $result['message']], 422);
        }

        return response()->json([
            'message' => $result['message'],
            'reset_token' => $request->code,
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|string|size:6',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $verificationCode = \App\Models\VerificationCode::where('user_id', $user->id)
            ->where('type', 'password_reset')
            ->where('code', $request->code)
            ->where('is_used', true)
            ->latest()
            ->first();

        if (!$verificationCode) {
            return response()->json(['message' => 'Invalid or unverified code.'], 422);
        }

        $user->update(['password' => Hash::make($request->password)]);

        return response()->json([
            'message' => 'Password has been reset successfully.',
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = auth()->user();

        $request->validate([
            'profile_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if (!$request->hasFile('profile_image')) {
            return response()->json(['message' => 'No image provided.'], 422);
        }

        $imagePath = $request->file('profile_image')->store('profile_images', 'public');

        if ($user->role === 'student') {
            $profile = $user->student;
            if (!$profile) {
                return response()->json(['message' => 'Student profile not found.'], 404);
            }
            if ($profile->profile_image && Storage::disk('public')->exists($profile->profile_image)) {
                Storage::disk('public')->delete($profile->profile_image);
            }
            $profile->update(['profile_image' => $imagePath]);
        } elseif ($user->role === 'teacher') {
            $profile = $user->teacher;
            if (!$profile) {
                return response()->json(['message' => 'Teacher profile not found.'], 404);
            }
            if ($profile->profile_image && Storage::disk('public')->exists($profile->profile_image)) {
                Storage::disk('public')->delete($profile->profile_image);
            }
            $profile->update(['profile_image' => $imagePath]);
        } else {
            return response()->json(['message' => 'Profile image not supported for this role.'], 422);
        }

        $fullUrl = url('/storage/' . $imagePath);

        $user->load(['student', 'teacher']);

        return response()->json([
            'message' => 'Profile image updated successfully.',
            'data' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'address' => $user->address,
                'phone' => $user->phone,
                'role' => $user->role,
                'profile_image' => $fullUrl,
            ],
        ]);
    }
}
