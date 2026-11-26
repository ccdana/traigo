<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Management\Authorizations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Lo que Dirección autoriza o devuelve. */
class AuthorizationController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Authorizations::pending()]);
    }

    public function show(string $type, string $id): JsonResponse
    {
        return response()->json(['data' => Authorizations::of($type)->detail($id)]);
    }

    public function approve(Request $request, string $type, string $id): Response
    {
        Authorizations::of($type)->approve($request->user(), $id);

        return response()->noContent();
    }

    public function sendBack(Request $request, string $type, string $id): Response
    {
        $data = $request->validate(
            ['reason' => ['required', 'string', 'max:500']],
            ['reason.required' => 'Escribe el motivo.'],
        );

        Authorizations::of($type)->sendBack($request->user(), $id, $data['reason']);

        return response()->noContent();
    }
}
