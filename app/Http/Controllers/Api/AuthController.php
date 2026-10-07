<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuthChallenge;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Normalize Bangladeshi mobile numbers to standard E.164 (+8801XXXXXXXXX).
     */
    protected function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/[^\d]/', '', $phone);

        if (str_starts_with($digits, '880') && strlen($digits) === 13) {
            return '+' . $digits;
        }
        if (str_starts_with($digits, '01') && strlen($digits) === 11) {
            return '+88' . $digits;
        }
        if (str_starts_with($digits, '1') && strlen($digits) === 10) {
            return '+880' . $digits;
        }

        return '+' . $digits;
    }

    /**
     * Register a new user with mobile number and password.
     */
    public function register(Request $request): JsonResponse
    {
        $normalizedPhone = $this->normalizePhone($request->input('phone', ''));
        $request->merge(['normalized_phone' => $normalizedPhone]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'username' => [
                'required',
                'string',
                'min:3',
                'max:30',
                'alpha_dash',
                'unique:users,username',
            ],
            'normalized_phone' => ['required', 'string', 'min:11', 'max:20', 'unique:users,phone'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
        ], [
            'normalized_phone.unique' => 'This mobile number is already registered.',
            'username.unique' => 'This username handle is already taken.',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'username' => Str::lower($validated['username']),
            'phone' => $validated['normalized_phone'],
            'email' => $validated['email'] ?? null,
            'password' => Hash::make($validated['password']),
            'status' => 'active',
            'last_login_at' => now(),
        ]);

        // Automatically initialize default public profile
        $user->profile()->create([
            'bio' => 'New curator on JashoreBro 🔥',
            'locality' => 'Jashore',
        ]);

        $token = $user->createToken('jb-mobile-web-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Registration successful! Welcome to JashoreBro.',
            'data' => [
                'user' => $user->load('profile', 'roles'),
                'token' => $token,
            ],
        ], 201);
    }

    /**
     * Authenticate with mobile number and password.
     */
    public function login(Request $request): JsonResponse
    {
        $normalizedPhone = $this->normalizePhone($request->input('phone', ''));

        $request->validate([
            'phone' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('phone', $normalizedPhone)
            ->orWhere('phone', $request->input('phone'))
            ->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'phone' => ['The provided mobile number or password is incorrect.'],
            ]);
        }

        if ($user->isSuspended()) {
            return response()->json([
                'success' => false,
                'message' => 'Your account is currently suspended. Reason: ' . ($user->status_reason ?? 'Contact support.'),
            ], 403);
        }

        $user->update(['last_login_at' => now()]);

        $token = $user->createToken('jb-mobile-web-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'data' => [
                'user' => $user->load('profile', 'roles'),
                'token' => $token,
            ],
        ]);
    }

    /**
     * Request an OTP challenge for phone verification or password recovery.
     */
    public function sendOtp(Request $request): JsonResponse
    {
        $normalizedPhone = $this->normalizePhone($request->input('phone', ''));
        $purpose = $request->input('purpose', 'phone_verification');

        $request->validate([
            'phone' => ['required', 'string'],
            'purpose' => ['required', 'in:phone_verification,password_recovery,login'],
        ]);

        // Rate limit: prevent flooding OTP requests within 60 seconds
        $recentChallenge = AuthChallenge::where('destination', $normalizedPhone)
            ->where('purpose', $purpose)
            ->where('created_at', '>=', now()->subSeconds(60))
            ->first();

        if ($recentChallenge) {
            return response()->json([
                'success' => false,
                'message' => 'Please wait 60 seconds before requesting another code.',
            ], 429);
        }

        // Generate 6-digit OTP code (standard demo default 123456 or random)
        $code = config('app.env') === 'production' ? (string) random_int(100000, 999999) : '123456';

        $challenge = AuthChallenge::create([
            'user_id' => auth('sanctum')->id(),
            'channel' => 'phone',
            'destination' => $normalizedPhone,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Verification code sent to ' . $normalizedPhone,
            'data' => [
                'expires_at' => $challenge->expires_at,
                // In local/testing mode, return debug_code for instant developer testing
                'debug_code' => config('app.debug') ? $code : null,
            ],
        ]);
    }

    /**
     * Verify an OTP challenge.
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $normalizedPhone = $this->normalizePhone($request->input('phone', ''));
        $purpose = $request->input('purpose', 'phone_verification');
        $code = $request->input('code');

        $request->validate([
            'phone' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
            'purpose' => ['required', 'in:phone_verification,password_recovery,login'],
        ]);

        $challenge = AuthChallenge::where('destination', $normalizedPhone)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if (! $challenge) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired verification code. Please request a new code.',
            ], 422);
        }

        if ($challenge->hasExceededAttempts(5)) {
            return response()->json([
                'success' => false,
                'message' => 'Too many failed attempts. This code is invalidated.',
            ], 429);
        }

        if (! Hash::check($code, $challenge->code_hash)) {
            $challenge->increment('attempts');
            return response()->json([
                'success' => false,
                'message' => 'Incorrect verification code. Please try again.',
            ], 422);
        }

        $challenge->update(['consumed_at' => now()]);

        // If phone verification, mark the user's phone verified
        $user = User::where('phone', $normalizedPhone)->first();
        if ($user && $purpose === 'phone_verification') {
            $user->update(['phone_verified_at' => now()]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Verification successful.',
        ]);
    }

    /**
     * Get the authenticated user's profile and roles.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'user' => $request->user()->load('profile', 'roles', 'addresses'),
            ],
        ]);
    }

    /**
     * Revoke current token and log out.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully.',
        ]);
    }
}
