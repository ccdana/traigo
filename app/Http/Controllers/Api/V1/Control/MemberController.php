<?php

namespace App\Http\Controllers\Api\V1\Control;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\MemberAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Los accesos del equipo. Los de Jime y Dirección los cambia quien administra
 * el servidor (`traigo:pin`).
 */
class MemberController extends Controller
{
    public function index(): JsonResponse
    {
        $members = User::query()
            ->join('team_profiles as p', 'p.user_id', '=', 'users.id_user')
            ->orderBy('p.sort_order')
            ->get('users.*')
            ->map(fn (User $user) => [
                'id' => $user->getKey(),
                'code' => $user->code,
                'name' => $user->name,
                'title' => $user->title,
            ]);

        return response()->json(['data' => $members]);
    }

    public function resetPin(Request $request, string $member): Response
    {
        $data = $request->validate(
            ['pin' => ['required', 'string', 'regex:/^\d{4}$/']],
            ['pin.required' => 'El PIN tiene 4 números.', 'pin.regex' => 'El PIN tiene 4 números.'],
        );

        MemberAccess::resetPin($this->teamMember($member), $data['pin'], $request->user());

        return response()->noContent();
    }

    public function revoke(Request $request, string $member): Response
    {
        MemberAccess::revokeSessions($this->teamMember($member), $request->user());

        return response()->noContent();
    }

    private function teamMember(string $id): User
    {
        return User::query()
            ->whereIn('id_user', DB::table('team_profiles')->select('user_id'))
            ->find($id)
            ?? throw ApiException::notFound('Solo se cambian las cuentas del equipo.');
    }
}
