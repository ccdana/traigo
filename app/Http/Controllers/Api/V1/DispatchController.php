<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Warehouse\Dispatches;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Los pedidos que almacén prepara y entrega. */
class DispatchController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Dispatches::queue()]);
    }

    public function ready(Request $request, string $order, Dispatches $dispatches): Response
    {
        $dispatches->ready($request->user(), $order);

        return response()->noContent();
    }

    public function delivered(Request $request, string $order, Dispatches $dispatches): Response
    {
        $dispatches->deliver($request->user(), $order);

        return response()->noContent();
    }
}
