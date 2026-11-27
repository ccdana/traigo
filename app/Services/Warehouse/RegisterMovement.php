<?php

namespace App\Services\Warehouse;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Sales\Stock;
use Illuminate\Support\Facades\DB;

/**
 * Entradas de mercadería y ajustes. Quedan en el kardex, que no se edita: un
 * error se corrige con otro movimiento. La diferencia de un conteo se corrige
 * una sola vez y exactamente por lo que faltó o sobró.
 */
class RegisterMovement
{
    private const ADJUSTMENTS = ['adjustment_in', 'adjustment_out'];

    /**
     * @param  array{product_id: string, type: string, quantity: int|string, note?: ?string, inventory_count_id?: ?string}  $data  ya validada
     * @param  'app'|'admin'  $source  desde dónde se registra (la app o el panel web)
     * @return array{product_id: string, stock: int, reserved: int, available: int}
     */
    public function register(User $warehouse, array $data, string $clientUuid, string $source = 'app'): array
    {
        $productId = $data['product_id'];
        $type = $data['type'];
        $quantity = (int) $data['quantity'];
        $note = filled($data['note'] ?? null) ? trim($data['note']) : null;
        $countId = $data['inventory_count_id'] ?? null;

        return DB::transaction(function () use ($warehouse, $productId, $type, $quantity, $note, $countId, $clientUuid, $source) {
            // Segunda barrera contra duplicados, por si se perdió la respuesta
            // guardada del primer envío.
            $previous = DB::table('stock_movements')->where('client_uuid', $clientUuid)->first();

            if ($previous) {
                if ($previous->created_by !== $warehouse->getKey()) {
                    throw ApiException::conflict('idempotency_conflict', 'Esa clave ya se usó para otro envío.');
                }

                return $this->level($previous->product_id);
            }

            $adds = in_array($type, ['initial', 'purchase', 'adjustment_in'], true);

            if ($adds !== ($quantity > 0)) {
                throw ApiException::invalid('La cantidad no coincide con el tipo de movimiento.');
            }

            if ($note === null && in_array($type, self::ADJUSTMENTS, true)) {
                throw ApiException::invalid('Escribe el motivo del ajuste.', 'note_required');
            }

            // Un producto nuevo todavía no tiene su fila de inventario.
            DB::table('inventory')->insertOrIgnore(['product_id' => $productId]);
            $row = Stock::lock([$productId])->get($productId);

            if ($countId !== null) {
                $this->ensureMatchesCount($countId, $productId, $type, $quantity);
            }

            if ($row->stock + $quantity < $row->reserved) {
                throw ApiException::conflict(
                    'below_reserved',
                    "No puede quedar menos de las {$row->reserved} unidades reservadas por pedidos.",
                );
            }

            DB::table('inventory')->where('product_id', $productId)->update([
                'stock' => DB::raw('stock + '.$quantity),
                'updated_at' => now(),
            ]);
            DB::table('stock_movements')->insert([
                'product_id' => $productId,
                'type' => $type,
                'quantity' => $quantity,
                'inventory_count_id' => $countId,
                'client_uuid' => $clientUuid,
                'source' => $source,
                'created_by' => $warehouse->getKey(),
                'note' => $note,
            ]);

            return $this->level($productId);
        });
    }

    /** Con la fila de inventario ya bloqueada, así no se corrige dos veces. */
    private function ensureMatchesCount(string $countId, string $productId, string $type, int $quantity): void
    {
        $item = DB::table('inventory_count_items')
            ->where('inventory_count_id', $countId)
            ->where('product_id', $productId)
            ->first()
            ?? throw ApiException::notFound('Ese producto no está en el conteo.');

        $adjusted = DB::table('stock_movements')
            ->where('inventory_count_id', $countId)
            ->where('product_id', $productId)
            ->exists();

        if ($adjusted) {
            throw ApiException::conflict('already_adjusted', 'Esa diferencia ya se corrigió.');
        }

        if (! in_array($type, self::ADJUSTMENTS, true) || $quantity !== $item->difference) {
            throw ApiException::invalid('El ajuste no coincide con el conteo.');
        }
    }

    /** @return array{product_id: string, stock: int, reserved: int, available: int} */
    private function level(string $productId): array
    {
        $row = DB::table('inventory')->where('product_id', $productId)->first();

        return [
            'product_id' => $productId,
            'stock' => $row->stock,
            'reserved' => $row->reserved,
            'available' => $row->available,
        ];
    }
}
