<?php

namespace App\Http\Controllers\Api\V1\Control;

use App\Http\Controllers\Controller;
use App\Services\Control\ControlOrders;
use App\Support\Responses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Todos los pedidos: devoluciones y duplicados. */
class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return Responses::paginated(ControlOrders::search($data['search'] ?? null), ControlOrders::summary(...));
    }

    public function show(string $order): JsonResponse
    {
        return response()->json(['data' => ControlOrders::view($order)]);
    }

    public function returnOrder(Request $request, string $order, ControlOrders $orders): Response
    {
        $data = $request->validate(
            ['reason' => ['required', 'string', 'max:500']],
            ['reason.required' => 'Escribe el motivo.'],
        );

        $orders->returnOrder($request->user(), $order, $data['reason']);

        return response()->noContent();
    }

    public function duplicate(Request $request, string $order, ControlOrders $orders): Response
    {
        $data = $request->validate(
            ['original_order_id' => ['required', 'uuid']],
            ['original_order_id.required' => 'Elige el pedido original.'],
        );

        $orders->markDuplicate($request->user(), $order, $data['original_order_id']);

        return response()->noContent();
    }
}
