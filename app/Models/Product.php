<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Product extends Model
{
    use HasUuids;

    protected $primaryKey = 'id_product';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'wholesale_min_qty' => 'integer',
        ];
    }

    /**
     * Cada producto con lo que muestra el catálogo: categoría, stock y la
     * imagen principal.
     */
    public function scopeForCatalog(Builder $query): void
    {
        $query
            ->leftJoin('categories', 'categories.id_category', '=', 'products.category_id')
            ->leftJoin('inventory', 'inventory.product_id', '=', 'products.id_product')
            ->select([
                'products.*',
                'categories.name as category_name',
                DB::raw('coalesce(inventory.stock, 0) as stock'),
                DB::raw('coalesce(inventory.reserved, 0) as reserved'),
                DB::raw('(select url from product_images where product_images.product_id = products.id_product order by position, created_at limit 1) as image_url'),
            ]);
    }

    /** Sin distinguir mayúsculas ni tildes, en el nombre o el código. */
    public function scopeMatching(Builder $query, string $search): void
    {
        $term = addcslashes(self::fold($search), '%_\\');

        $query->whereRaw(self::foldSql("products.name || ' ' || products.item_code").' like ?', ["%{$term}%"]);
    }

    public static function foldSql(string $expression): string
    {
        return "translate(lower({$expression}), 'áàäâéèëêíìïîóòöôúùüûñ', 'aaaaeeeeiiiioooouuuun')";
    }

    public static function fold(string $text): string
    {
        return strtr(mb_strtolower(trim($text)), [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ñ' => 'n',
        ]);
    }
}
