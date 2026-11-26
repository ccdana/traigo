<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Warehouse\InventoryCounts;
use App\Services\Warehouse\RegisterMovement;
use App\Support\ClientKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Stock y conteos de almacén. Jime solo mira los conteos. */
class InventoryController extends Controller
{
    private const MESSAGES = [
        'product_id.exists' => 'Ese producto ya no está en el catálogo.',
        'quantity.not_in' => 'La cantidad no puede ser cero.',
        'counted_on.before_or_equal' => 'La fecha del conteo no puede ser futura.',
        'items.required' => 'Revisa las cantidades contadas.',
        'items.*.product_id.distinct' => 'Hay un producto repetido en el conteo.',
        'items.*.product_id.exists' => 'Un producto del conteo ya no está en el catálogo.',
        'items.*.counted_qty.required' => 'Revisa las cantidades contadas.',
        'items.*.counted_qty.integer' => 'Revisa las cantidades contadas.',
        'items.*.counted_qty.min' => 'Revisa las cantidades contadas.',
        'items.*.counted_qty.max' => 'Revisa las cantidades contadas.',
    ];

    public function movement(Request $request, RegisterMovement $movements): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['bail', 'required', 'uuid', Rule::exists('products', 'id_product')],
            // Las ventas y devoluciones las registra el sistema.
            'type' => ['required', Rule::in(['initial', 'purchase', 'adjustment_in', 'adjustment_out'])],
            'quantity' => ['required', 'integer', 'between:-100000,100000', 'not_in:0'],
            'note' => ['nullable', 'string', 'max:500'],
            'inventory_count_id' => ['nullable', 'uuid'],
        ], self::MESSAGES);

        $level = $movements->register($request->user(), $data, ClientKey::require($request));

        return response()->json(['data' => $level], 201);
    }

    public function counts(): JsonResponse
    {
        return response()->json(['data' => InventoryCounts::all()]);
    }

    public function storeCount(Request $request, InventoryCounts $counts): JsonResponse
    {
        $data = $request->validate([
            'counted_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.today()->toDateString()],
            'items' => ['required', 'array', 'max:1000'],
            'items.*.product_id' => ['bail', 'required', 'uuid', 'distinct', Rule::exists('products', 'id_product')],
            'items.*.counted_qty' => ['required', 'integer', 'min:0', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:500'],
        ], self::MESSAGES);

        ClientKey::require($request);

        $count = $counts->submit(
            $request->user(),
            $data['counted_on'],
            array_values($data['items']),
            $data['note'] ?? null,
        );

        return response()->json(['data' => $count], 201);
    }
}
