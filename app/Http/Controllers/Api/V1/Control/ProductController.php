<?php

namespace App\Http\Controllers\Api\V1\Control;

use App\Http\Controllers\Controller;
use App\Services\Control\PriceChanges;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Precios autorizados: Jime propone; Dirección aprueba. */
class ProductController extends Controller
{
    private const MONEY = ['nullable', 'regex:/^\d{1,9}(\.\d{1,2})?$/'];

    public function show(string $product): JsonResponse
    {
        return response()->json(['data' => PriceChanges::sheet($product)]);
    }

    public function propose(Request $request, string $product, PriceChanges $prices): JsonResponse
    {
        $data = $request->validate([
            'price_unit' => self::MONEY,
            'price_wholesale' => self::MONEY,
            'wholesale_min_qty' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'commission_pct' => ['nullable', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'cost' => self::MONEY,
            'reason' => ['nullable', 'string', 'max:500'],
        ], [
            'price_unit.regex' => 'Revisa el precio por unidad.',
            'price_wholesale.regex' => 'Revisa el precio por mayor.',
            'commission_pct.regex' => 'La comisión va de 0 a 100 %.',
            'cost.regex' => 'Revisa el costo.',
        ]);

        $prices->propose(
            $request->user(),
            $product,
            array_map(
                fn ($value) => $value === null ? null : (string) $value,
                collect($data)->only(array_keys(PriceChanges::FIELDS))->all(),
            ),
            $data['reason'] ?? null,
        );

        return response()->json(['data' => PriceChanges::sheet($product)], 201);
    }
}
