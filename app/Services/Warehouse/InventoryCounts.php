<?php

namespace App\Services\Warehouse;

use App\Models\User;
use App\Services\Sales\Stock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Conteos físicos contra el sistema (KPI «Exactitud del inventario»). Guardan
 * lo que había en bodega en ese momento y no cambian el stock: las diferencias
 * se corrigen con ajustes.
 */
class InventoryCounts
{
    /**
     * Del más nuevo al más antiguo.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return self::view(DB::table('inventory_counts')
            ->orderByDesc('counted_on')
            ->orderByDesc('created_at')
            ->pluck('id_inventory_count')
            ->all());
    }

    /**
     * @param  list<array{product_id: string, counted_qty: int|string}>  $items  ya validada
     * @return array<string, mixed>
     */
    public function submit(User $warehouse, string $countedOn, array $items, ?string $note): array
    {
        $id = DB::transaction(function () use ($warehouse, $countedOn, $items, $note): string {
            // Bloqueado para que una entrega a la vez no cambie la foto.
            $inventory = Stock::lock(array_column($items, 'product_id'));
            $id = (string) Str::uuid7();

            DB::table('inventory_counts')->insert([
                'id_inventory_count' => $id,
                'counted_on' => $countedOn,
                'counted_by' => $warehouse->getKey(),
                'note' => filled($note) ? trim($note) : null,
                'closed_at' => now(),
            ]);
            DB::table('inventory_count_items')->insert(array_map(fn (array $item) => [
                'inventory_count_id' => $id,
                'product_id' => $item['product_id'],
                // Lo que hay en bodega, con lo apartado.
                'system_qty' => $inventory->get($item['product_id'])?->stock ?? 0,
                'counted_qty' => (int) $item['counted_qty'],
            ], $items));

            return $id;
        });

        return self::view([$id])[0];
    }

    /**
     * @param  list<string>  $ids  en el orden en que se devuelven
     * @return list<array<string, mixed>>
     */
    private static function view(array $ids): array
    {
        $counts = DB::table('inventory_counts')
            ->whereIn('id_inventory_count', $ids)
            ->get()
            ->keyBy('id_inventory_count');
        $items = DB::table('inventory_count_items as i')
            ->join('products as p', 'p.id_product', '=', 'i.product_id')
            ->whereIn('i.inventory_count_id', $ids)
            ->orderBy('p.name')
            ->orderBy('p.item_code')
            ->get([
                'i.inventory_count_id', 'i.product_id', 'p.item_code', 'p.name',
                'i.system_qty', 'i.counted_qty',
            ])
            ->groupBy('inventory_count_id');
        // Corregido: ya hay un ajuste de ese conteo para ese producto.
        $adjusted = DB::table('stock_movements')
            ->whereIn('inventory_count_id', $ids)
            ->get(['inventory_count_id', 'product_id'])
            ->mapWithKeys(fn (object $row) => ["{$row->inventory_count_id}/{$row->product_id}" => true]);

        return array_map(fn (string $id) => [
            'id' => $id,
            'counted_on' => $counts->get($id)->counted_on,
            'items' => $items->get($id, collect())->map(fn (object $item) => [
                'product_id' => $item->product_id,
                'item_code' => $item->item_code,
                'name' => $item->name,
                'system_qty' => $item->system_qty,
                'counted_qty' => $item->counted_qty,
                'adjusted' => $adjusted->has("{$id}/{$item->product_id}"),
            ])->values()->all(),
        ], $ids);
    }
}
