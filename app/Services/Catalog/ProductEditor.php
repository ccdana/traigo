<?php

namespace App\Services\Catalog;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Control\PriceChanges;
use App\Support\AuditNote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Alta y datos de productos desde el panel. Un producto nuevo nace fuera de la
 * venta y solo Dirección lo publica. Después, precios, comisión y costo solo
 * cambian con una propuesta autorizada ({@see PriceChanges}).
 */
class ProductEditor
{
    /** Lo que se puede corregir sin autorización. */
    private const DETAILS = ['name', 'category_id', 'description', 'gift_audience'];

    /** @param  array<string, mixed>  $data  ya validado, con las columnas de `products` */
    public function create(User $by, array $data): string
    {
        $prices = collect($data)->only(array_values(PriceChanges::FIELDS))->filter(fn ($value) => $value !== null)->all();
        $empty = (object) array_fill_keys(array_values(PriceChanges::FIELDS), null);

        // Las mismas reglas de precios que en un cambio autorizado.
        if ($problem = PriceChanges::problem($empty, $prices)) {
            throw ApiException::invalid($problem);
        }

        return DB::transaction(function () use ($by, $data): string {
            $id = (string) Str::uuid7();
            $code = Str::upper(trim($data['item_code']));

            DB::table('products')->insert([
                ...$data,
                'id_product' => $id,
                'item_code' => $code,
                'name' => Str::squish($data['name']),
                'slug' => $this->slug($data['name'], $code),
                'is_active' => false,
            ]);
            DB::table('inventory')->insert(['product_id' => $id]);
            // El alta no la registra la base por sí sola.
            AuditNote::add($by, 'product_created', 'products', $id, ['name' => Str::squish($data['name'])]);

            return $id;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function updateDetails(string $productId, array $data): void
    {
        $values = collect($data)->only(self::DETAILS)->all();

        // La dirección del producto no cambia: los enlaces compartidos siguen.
        DB::table('products')->where('id_product', $productId)->update([
            ...$values,
            'name' => Str::squish($values['name']),
            'updated_at' => now(),
        ]);
    }

    /** Quitar de la venta lo puede hacer Jime; publicar, solo Dirección. */
    public function setActive(User $by, string $productId, bool $active): void
    {
        if ($active && ! $by->role->canAuthorize()) {
            throw ApiException::forbidden('Solo Dirección puede poner un producto a la venta.');
        }

        DB::table('products')->where('id_product', $productId)->update(['is_active' => $active, 'updated_at' => now()]);
    }

    private function slug(string $name, string $code): string
    {
        $base = Str::slug("{$name} {$code}") ?: Str::slug($code) ?: 'producto';
        $slug = $base;

        for ($n = 2; DB::table('products')->where('slug', $slug)->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }
}
