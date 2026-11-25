<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Member;
use App\Models\TwoFactorChallenge;
use App\Models\User;
use App\Services\Auth\PinLogin;
use App\Services\Auth\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Ingreso, segundo paso, salida y «quién soy». */
class AuthController extends Controller
{
    public function __construct(
        private readonly PinLogin $pinLogin,
        private readonly TwoFactor $twoFactor,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'pin' => ['required', 'string', 'max:12'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $user = $this->pinLogin->check($data['code'], $data['pin']);

        // Jime y Dirección: todavía sin token.
        if ($user->role->requiresSecondStep()) {
            return response()->json([
                'data' => ['two_factor' => $this->challenge($this->twoFactor->start($user))],
            ]);
        }

        return $this->session($user, $data['device_name']);
    }

    public function verifyTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_id' => ['required', 'string', 'max:64'],
            'code' => ['required', 'string', 'max:12'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $user = $this->twoFactor->verify($data['challenge_id'], $data['code']);

        return $this->session($user, $data['device_name']);
    }

    public function resendTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate(['challenge_id' => ['required', 'string', 'max:64']]);

        return response()->json([
            'data' => $this->challenge($this->twoFactor->resend($data['challenge_id'])),
        ]);
    }

    /** Cierra solo la sesión de este celular. */
    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => ['member' => Member::toArray($request->user())]]);
    }

    private function session(User $user, string $deviceName): JsonResponse
    {
        $user->update(['last_login_at' => now()]);

        return response()->json([
            'data' => [
                'token' => $user->createToken($deviceName)->plainTextToken,
                'member' => Member::toArray($user),
            ],
        ]);
    }

    /** @return array<string, string|int> */
    private function challenge(TwoFactorChallenge $challenge): array
    {
        return [
            'challenge_id' => $challenge->getKey(),
            'expires_at' => $challenge->expires_at->timezone(config('app.timezone'))->toIso8601String(),
            'resend_at' => $challenge->resend_at->timezone(config('app.timezone'))->toIso8601String(),
            'code_length' => TwoFactor::CODE_LENGTH,
        ];
    }
}
