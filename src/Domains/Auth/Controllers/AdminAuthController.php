<?php

declare(strict_types=1);

namespace EcApi\Domains\Auth\Controllers;

use EcApi\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

final class AdminAuthController extends Controller
{
    public function register(Request $request)
    {
        $credentials = $request->only('name', 'email', 'password');

        $validator = Validator::make($credentials, [
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:users,email',
            'username' => 'nullable|string',
            'password' => 'required|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => isset($request->email) ? $request->email : $this->generateUniqueEmail($request->username),
            'username' => isset($request->username) ? $request->username : '',
            'password' => Hash::make($request->password),
        ]);

        $token = $user->createToken('admin')->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'bearer',
            'user' => $user,
        ], 201);
    }

    private function generateUniqueEmail($username)
    {
        $baseEmail = $username . '@everestchronicle.com';

        do {
            $randomNumber = rand(10, 99);
            $email = $username . $randomNumber . '@everestchronicle.com';
        } while (User::where('email', $email)->exists());

        return $email;
    }


    public function login(Request $request)
    {
        $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        try {
            $login = trim((string) $request->input('username'));
            $rawPassword = (string) $request->input('password');

            // Support base64 password from frontend.
            $decodedPassword = base64_decode($rawPassword, true);
            $passwords = array_values(array_unique(array_filter([
                $decodedPassword !== false && $decodedPassword !== '' ? $decodedPassword : null,
                $rawPassword,
            ])));

            $user = User::query()
                ->where(function ($query) use ($login) {
                    $query->where('username', $login)
                        ->orWhere('email', $login);
                })
                ->first();

            if (! $user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid username/email or password.',
                ], 401);
            }

            $authenticated = false;
            $matchedPassword = null;
            $storedPassword = (string) $user->password;

            foreach ($passwords as $candidate) {
                // 1. Native PHP password_verify (handles $2a$, $2b$, $2y$, etc.)
                if (@password_verify($candidate, $storedPassword)) {
                    $authenticated = true;
                    $matchedPassword = $candidate;
                    break;
                }

                // 2. If it's a $2a$ hash, also test normalized to $2y$
                if (str_starts_with($storedPassword, '$2a$')) {
                    $normalized = '$2y$' . substr($storedPassword, 4);
                    if (@password_verify($candidate, $normalized)) {
                        $authenticated = true;
                        $matchedPassword = $candidate;
                        break;
                    }
                }

                // 3. Plain-text comparison (in case raw text is stored)
                if (hash_equals($storedPassword, $candidate)) {
                    $authenticated = true;
                    $matchedPassword = $candidate;
                    break;
                }

                // 4. Safe Laravel Hash::check
                try {
                    if (Hash::check($candidate, $storedPassword)) {
                        $authenticated = true;
                        $matchedPassword = $candidate;
                        break;
                    }
                } catch (\Throwable) {
                    // Ignore Bcrypt algorithm mismatch
                }
            }

            if (! $authenticated) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid username/email or password.',
                ], 401);
            }

            // Auto-upgrade legacy $2a$ or plain-text password to standard Laravel $2y$ Bcrypt
            if (! str_starts_with($storedPassword, '$2y$') && $matchedPassword !== null) {
                try {
                    $user->password = Hash::make($matchedPassword);
                    $user->save();
                } catch (\Throwable $e) {
                    Log::warning('Unable to auto-rehash user password: ' . $e->getMessage());
                }
            }

            // Optional: remove previous tokens if you only want one active login.
            // $user->tokens()->delete();

            $token = $user->createToken('admin')->plainTextToken;

            return response()->json([
                'status' => 'success',
                'message' => 'Login successful.',
                'access_token' => $token,
                'token_type' => 'Bearer',
                'admin' => $user,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Login Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'An unexpected error occurred. Please try again later.',
            ], 500);
        }
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        if ($user && $request->user()->currentAccessToken()) {
            $request->user()->currentAccessToken()->delete();
        }

        return response()->json(['message' => 'Successfully logged out'], 200);
    }

    public function getProfile(Request $request)
    {
        try {
            $user = $request->user();

            if (! $user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            return response()->json([
                'status' => 'success',
                'data' => $user,
                'admin' => $user,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An unexpected error occurred. Please try again later.',
            ], 500);
        }
    }

    public function updateProfile(Request $request)
    {
        $user = Auth::user();
    }
}
