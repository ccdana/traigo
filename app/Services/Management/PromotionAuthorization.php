<?php

namespace App\Services\Management;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Control\PriceChanges;
use App\Support\AuditNote;
use App\Support\Dates;
use App\Support\Lists;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Las promociones de la tienda web (todavía sin pantalla para crearlas). Solo
 * se publican autorizadas y ninguna baja del precio por mayor.
 */
class PromotionAuthorization implements AuthorizationKind
{
    public function pending(): array
    {
        return $this->promotions()
            ->orderBy('p.created_at')
            ->get()
            ->map(fn (object $row) => $this->item($row))
            ->all();
    }

    public function detail(string $id): array
    {
        $row = $this->promotions()->where('p.id_promotion', $this->uuid($id))->first()
            ?? throw Authorizations::gone();
        $products = $this->products($id);
        $blocked = $this->belowWholesale($row, $products);

        return [
            ...$this->item($row),
            'pays' => [],
            'unpaid' => [],
            'checks' => array_values(array_filter([
                $row->applies_to_wholesale ? 'También en ventas por mayor.' : null,
                'Ninguna oferta baja del precio por mayor.',
            ])),
            'changes' => array_values(array_filter([
                $this->change('Descuento', $this->discount($row)),
                $this->change('Productos', $products->isEmpty() ? 'Sin productos' : Lists::join($products->pluck('name')->all())),
                $this->change('Vigencia', 'desde el '.Dates::short($row->starts_at)
                    .($row->ends_at === null ? '' : ' hasta el '.Dates::short($row->ends_at))),
                $row->calendar_day === null ? null : $this->change('Puerta del calendario', (string) $row->calendar_day),
            ])),
            'can_approve' => $blocked === null,
            'blocked_reason' => $blocked,
        ];
    }

    public function approve(User $direction, string $id): void
    {
        DB::transaction(function () use ($direction, $id) {
            $promotion = $this->lockPending($id);

            if ($promotion->created_by === $direction->getKey()) {
                throw ApiException::conflict('own_record', 'Nadie aprueba lo propio.');
            }

            if ($problem = $this->belowWholesale($promotion, $this->products($id))) {
                throw ApiException::conflict('below_wholesale', $problem);
            }

            DB::table('promotions')->where('id_promotion', $id)->update([
                'status' => 'approved',
                'authorized_by' => $direction->getKey(),
                'authorized_at' => now(),
                'updated_at' => now(),
            ]);
            AuditNote::add($direction, 'promotion_approved', 'promotions', $id, ['name' => $promotion->name]);
        });
    }

    public function sendBack(User $direction, string $id, string $reason): void
    {
        DB::transaction(function () use ($direction, $id, $reason) {
            $promotion = $this->lockPending($id);

            DB::table('promotions')->where('id_promotion', $id)
                ->update(['status' => 'rejected', 'updated_at' => now()]);
            // La tabla no guarda el motivo: queda en el historial.
            AuditNote::add($direction, 'promotion_rejected', 'promotions', $id, [
                'name' => $promotion->name, 'note' => $reason,
            ]);
        });
    }

    private function belowWholesale(object $promotion, $products): ?string
    {
        if ($products->isEmpty()) {
            return 'La promoción todavía no tiene productos.';
        }

        $value = (float) $promotion->discount_value;

        foreach ($products as $product) {
            $unit = Money::toCents($product->price_unit_bs);
            $price = match ($promotion->discount_type) {
                'percentage' => (int) round($unit * (100 - $value) / 100),
                'fixed_amount' => $unit - (int) round($value * 100),
                'fixed_price' => (int) round($value * 100),
            };
            $floor = $product->price_wholesale_bs === null ? 1 : Money::toCents($product->price_wholesale_bs);

            if ($price < $floor) {
                return "La oferta dejaría «{$product->name}» en ".Money::display($price)
                    .', por debajo del precio por mayor ('.Money::display($floor).').';
            }
        }

        return null;
    }

    private function discount(object $row): string
    {
        $cents = (int) round((float) $row->discount_value * 100);

        return match ($row->discount_type) {
            'percentage' => PriceChanges::percent($row->discount_value),
            'fixed_amount' => Money::display($cents).' menos por unidad',
            'fixed_price' => Money::display($cents).' por unidad',
        };
    }

    private function change(string $label, string $after): array
    {
        return ['label' => $label, 'before' => null, 'after' => $after];
    }

    private function products(string $id)
    {
        return DB::table('promotion_products as pp')
            ->join('products as p', 'p.id_product', '=', 'pp.product_id')
            ->where('pp.promotion_id', $id)
            ->orderBy('p.name')
            ->get(['p.name', 'p.price_unit_bs', 'p.price_wholesale_bs']);
    }

    private function item(object $row): array
    {
        return Authorizations::item(
            'promotion',
            $row->id_promotion,
            "Promoción «{$row->name}»",
            $row->creator ?? 'Tienda web',
            $row->created_at,
        );
    }

    private function promotions()
    {
        return DB::table('promotions as p')
            ->leftJoin('users as u', 'u.id_user', '=', 'p.created_by')
            ->where('p.status', 'pending')
            ->where('p.is_active', true)
            ->select(['p.*', 'u.name as creator']);
    }

    private function lockPending(string $id): object
    {
        $promotion = DB::table('promotions')->where('id_promotion', $this->uuid($id))->lockForUpdate()->first();

        if (! $promotion || $promotion->status !== 'pending' || ! $promotion->is_active) {
            throw Authorizations::gone();
        }

        return $promotion;
    }

    private function uuid(string $id): string
    {
        return Str::isUuid($id) ? $id : throw Authorizations::gone();
    }
}
