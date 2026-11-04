<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $input = strtolower(trim((string) $request->input('email')));

        $user = $this->resolveLoginUser($input);

        if (! $user || ($user->is_active ?? true) === false || ! Hash::check((string) $request->input('password'), (string) $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('Email atau password salah.')],
            ]);
        }

        $deviceName = $request->header('X-Device-Name') ?: ($request->userAgent() ?: 'android');
        // Batasi panjang & buang karakter kontrol agar token name tidak jadi
        // tempat menyuntik data aneh ke tabel personal_access_tokens.
        $deviceName = trim(preg_replace('/[^\P{C}]+/u', '', (string) $deviceName) ?? '');
        if ($deviceName === '') {
            $deviceName = 'android';
        }
        $deviceName = mb_substr($deviceName, 0, 100);

        return response()->json([
            'token' => $user->createToken($deviceName)->plainTextToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }

    private function resolveLoginUser(string $input): ?User
    {
        if ($input === '') {
            return null;
        }

        $exact = User::query()->where('email', $input)->first();
        if ($exact) {
            return $exact;
        }

        if (str_contains($input, '@')) {
            return null;
        }

        $slug = implode('.', array_filter(explode(' ', preg_replace('/[^a-z0-9]+/', ' ', $input) ?? '')));

        $byLocalDomain = User::query()
            ->whereIn('email', array_unique([
                "{$input}@geumcheon.local",
                "{$slug}@geumcheon.local",
            ]))
            ->orWhereRaw('LOWER(name) = ?', [$input])
            ->first();

        if ($byLocalDomain) {
            return $byLocalDomain;
        }

        if ($slug !== '') {
            $prefixMatches = User::query()
                ->where('email', 'like', "{$slug}.%@geumcheon.local")
                ->limit(2)
                ->get();

            if ($prefixMatches->count() === 1) {
                return $prefixMatches->first();
            }
        }

        return null;
    }
}
