<?php

namespace App\Services\Control;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\Dates;
use App\Support\Money;
use App\Support\Numbers;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Precios autorizados. Jime propone solo lo que cambia; vale cuando Dirección
 * lo aprueba, y las ventas ya registradas conservan su precio. Si se aprueba el
 * % de un producto, lo toman las ventas sin % que no se liquidaron.
 */
class PriceChanges
{
    /** Nombre en la API → columna. */
    public const FIELDS = [
        'price_unit' => 'price_unit_bs',
        'price_wholesale' => 'price_wholesale_bs',
        'wholesale_min_qty' => 'wholesale_min_qty',
        'commission_pct' => 'commission_pct',
        'cost' => 'cost_bs',
    ];

    /** @return array<string, mixed> la ficha, con el cambio que espera */
    public static function sheet(string $productId): array
    {
        $product = DB::table('products as p')
            ->leftJoin('categories as c', 'c.id_category', '=', 'p.category_id')
            ->where('p.id_product', $productId)
            ->first(['p.*', 'c.name as category'])
            ?? throw self::missingProduct();
        $pending = DB::table('price_change_requests as r')
            ->join('users as u', 'u.id_user', '=', 'r.requested_by')
            ->where('r.product_id', $productId)
            ->where('r.status', 'pending')
            ->first(['r.*', 'u.name as requested_by_name']);

        return [
            'product_id' => $product->id_product,
            'item_code' => $product->item_code,
            'name' => $product->name,
            'category' => $product->category ?? 'Sin categoría',
            'current' => self::values($product),
            'pending_change' => $pending === null ? null : [
                'id' => $pending->id_price_change_request,
                'proposed' => array_filter(self::values($pending), fn ($value) => $value !== null),
                'requested_by' => $pending->requested_by_name,
                'requested_at' => Dates::toApi($pending->requested_at),
                'reason' => $pending->reason,
            ],
        ];
    }

    /** @param  array<string, int|string|null>  $proposed  por nombre de la API, ya con formato válido */
    public function propose(User $jime, string $productId, array $proposed, ?string $reason): void
    {
        DB::transaction(function () use ($jime, $productId, $proposed, $reason) {
            $product = DB::table('products')->where('id_product', $productId)->lockForUpdate()->first()
                ?? throw self::missingProduct();

            if (DB::table('price_change_requests')->where('product_id', $productId)->where('status', 'pending')->exists()) {
                throw ApiException::conflict('change_pending', 'Ese producto ya tiene un cambio esperando a Dirección.');
            }

            $changes = self::changes($product, $proposed);

            if ($changes === []) {
                throw ApiException::invalid('No hay cambios.');
            }

            if ($problem = self::problem($product, $changes)) {
                throw ApiException::invalid($problem);
            }

            DB::table('price_change_requests')->insert([
                'id_price_change_request' => (string) Str::uuid7(),
                'product_id' => $productId,
                'status' => 'pending',
                ...$changes,
                'previous_values' => json_encode(self::values($product)),
                'reason' => filled($reason) ? trim($reason) : null,
                'requested_by' => $jime->getKey(),
                'requested_at' => now(),
            ]);
        });
    }

    /** Aprueba y aplica en la misma transacción. */
    public function approve(User $direction, string $requestId): void
    {
        DB::transaction(function () use ($direction, $requestId) {
            $request = self::lockPending($requestId);
            $product = DB::table('products')->where('id_product', $request->product_id)->lockForUpdate()->first();

            if ($request->requested_by === $direction->getKey()) {
                throw ApiException::conflict('own_record', 'Nadie aprueba lo propio.');
            }

            $changes = self::requested($request);

            if ($problem = self::problem($product, $changes)) {
                throw ApiException::conflict('change_outdated', self::outdated($problem));
            }

            DB::table('products')->where('id_product', $product->id_product)
                ->update([...$changes, 'updated_at' => now()]);
            DB::table('price_change_requests')->where('id_price_change_request', $requestId)->update([
                'status' => 'approved',
                'decided_by' => $direction->getKey(),
                'decided_at' => now(),
            ]);

            if (isset($changes['commission_pct'])) {
                self::withoutRate($product->id_product)->update([
                    'rate_pct' => $changes['commission_pct'],
                    // La base exige el mismo redondeo: por eso lo calcula ella.
                    'amount_bs' => DB::raw('round(base_bs * '.(float) $changes['commission_pct'].' / 100, 2)'),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function reject(User $direction, string $requestId, string $note): void
    {
        DB::transaction(function () use ($direction, $requestId, $note) {
            self::lockPending($requestId);

            DB::table('price_change_requests')->where('id_price_change_request', $requestId)->update([
                'status' => 'rejected',
                'decided_by' => $direction->getKey(),
                'decided_at' => now(),
                'decision_note' => $note,
            ]);
        });
    }

    /** Las comisiones sin % de ese producto que no se liquidaron (y sus ajustes). */
    public static function withoutRate(string $productId): Builder
    {
        return DB::table('commissions')
            ->whereNull('rate_pct')
            ->whereNull('settlement_id')
            ->where('status', '<>', 'cancelled')
            ->whereIn('order_item_id', DB::table('order_items')->where('product_id', $productId)->select('id_order_item'));
    }

    /**
     * Lo pedido en una propuesta, por columna.
     *
     * @return array<string, int|string>
     */
    public static function requested(object $request): array
    {
        return array_filter(
            array_combine(
                array_values(self::FIELDS),
                array_map(fn (string $column) => $request->{$column}, array_values(self::FIELDS)),
            ),
            fn ($value) => $value !== null,
        );
    }

    /**
     * Por qué no se puede aplicar (o nulo): las mismas reglas que la base.
     *
     * @param  array<string, int|string>  $changes  por columna
     */
    public static function problem(object $product, array $changes): ?string
    {
        $value = fn (string $column) => array_key_exists($column, $changes) ? $changes[$column] : $product->{$column};
        $unit = Money::toCents($value('price_unit_bs'));
        $wholesale = $value('price_wholesale_bs');
        $minimum = $value('wholesale_min_qty');
        $commission = $value('commission_pct');

        return match (true) {
            $unit <= 0 || ($wholesale !== null && Money::toCents($wholesale) <= 0) => 'El precio no puede ser cero.',
            ($wholesale === null) !== ($minimum === null) => 'El precio por mayor y su mínimo van juntos.',
            $minimum !== null && (int) $minimum < 2 => 'El mínimo por mayor es desde 2 unidades.',
            $wholesale !== null && Money::toCents($wholesale) > $unit => 'El precio por mayor no puede ser mayor que el de unidad.',
            $commission !== null && (float) $commission > 100 => 'La comisión va de 0 a 100 %.',
            default => null,
        };
    }

    public static function outdated(string $problem): string
    {
        return "El producto cambió desde que Jime lo pidió. {$problem} Devuélvelo para que lo revise.";
    }

    private static function lockPending(string $requestId): object
    {
        $request = DB::table('price_change_requests')
            ->where('id_price_change_request', $requestId)
            ->lockForUpdate()
            ->first();

        if (! $request || $request->status !== 'pending') {
            throw ApiException::notFound('Eso ya no espera autorización.');
        }

        return $request;
    }

    /**
     * Solo lo que de verdad cambia, por columna.
     *
     * @return array<string, int|string>
     */
    private static function changes(object $product, array $proposed): array
    {
        $changes = [];

        foreach (self::FIELDS as $name => $column) {
            $value = $proposed[$name] ?? null;

            if ($value === null) {
                continue;
            }

            $current = $product->{$column};
            $same = $current !== null && match ($column) {
                'wholesale_min_qty' => (int) $current === (int) $value,
                default => Money::toCents((string) $current) === Money::toCents((string) $value),
            };

            if (! $same) {
                $changes[$column] = $column === 'wholesale_min_qty' ? (int) $value : (string) $value;
            }
        }

        return $changes;
    }

    /** @return array<string, int|string|null> por nombre de la API */
    private static function values(object $row): array
    {
        return [
            'price_unit' => Money::fromDatabase($row->price_unit_bs),
            'price_wholesale' => Money::fromDatabase($row->price_wholesale_bs),
            'wholesale_min_qty' => $row->wholesale_min_qty === null ? null : (int) $row->wholesale_min_qty,
            'commission_pct' => $row->commission_pct === null
                ? null
                : number_format((float) $row->commission_pct, 2, '.', ''),
            'cost' => Money::fromDatabase($row->cost_bs),
        ];
    }

    /** «5 %» o «2,5 %». */
    public static function percent(string|float $value): string
    {
        return str_replace('.', ',', (string) Numbers::plain($value)).' %';
    }

    private static function missingProduct(): ApiException
    {
        return ApiException::notFound('Ese producto ya no está en el catálogo.');
    }
}
