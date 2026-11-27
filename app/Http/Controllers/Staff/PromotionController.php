<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\Store\PromotionEditor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PromotionController extends Controller
{
    public const TYPES = [
        'percentage' => 'Porcentaje de descuento',
        'fixed_amount' => 'Bolivianos menos por unidad',
        'fixed_price' => 'Precio final por unidad',
    ];

    public function index(): View
    {
        return view('staff.promotions.index', [
            'promotions' => DB::table('promotions as p')
                ->leftJoin('users as u', 'u.id_user', '=', 'p.created_by')
                ->orderByDesc('p.is_active')
                ->orderByDesc('p.starts_at')
                ->select([
                    'p.*', 'u.name as creator',
                    DB::raw("(select string_agg(pr.name, ', ' order by pr.name) from promotion_products pp join products pr on pr.id_product = pp.product_id where pp.promotion_id = p.id_promotion) as products"),
                ])
                ->paginate(20),
            'types' => self::TYPES,
        ]);
    }

    public function create(): View
    {
        return view('staff.promotions.create', [
            'types' => self::TYPES,
            'products' => DB::table('products')->where('is_active', true)->orderBy('name')
                ->get(['id_product', 'item_code', 'name', 'price_unit_bs', 'price_wholesale_bs']),
        ]);
    }

    public function store(Request $request, PromotionEditor $editor): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'discount_type' => ['required', Rule::in(array_keys(self::TYPES))],
            'discount_value' => ['required', 'regex:/^\d{1,9}(\.\d{1,2})?$/', 'numeric', 'gt:0',
                Rule::when($request->input('discount_type') === 'percentage', ['max:100'])],
            'applies_to_wholesale' => ['nullable', 'boolean'],
            'calendar_day' => ['nullable', 'integer', 'between:1,24'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'required_with:calendar_day', 'date', 'after:starts_at'],
            'product_ids' => ['required', 'array', 'min:1', 'max:100'],
            'product_ids.*' => ['uuid', 'distinct'],
        ], ['product_ids.required' => 'Elige al menos un producto.'], [
            'name' => 'el nombre',
            'discount_type' => 'el tipo de descuento',
            'discount_value' => 'el descuento',
            'calendar_day' => 'la puerta del calendario',
            'starts_at' => 'el inicio',
            'ends_at' => 'el fin',
        ]);

        $local = fn (?string $value) => $value === null ? null : Carbon::parse($value, config('app.timezone'));

        $editor->create($request->user(), [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'discount_type' => $data['discount_type'],
            'discount_value' => $data['discount_value'],
            'applies_to_wholesale' => $request->boolean('applies_to_wholesale'),
            'calendar_day' => isset($data['calendar_day']) ? (int) $data['calendar_day'] : null,
            'starts_at' => $local($data['starts_at']),
            'ends_at' => $local($data['ends_at'] ?? null),
        ], array_values($data['product_ids']));

        return to_route('staff.promotions.index')->with('status', 'Oferta enviada a Dirección. Se publica cuando la autorice.');
    }

    public function withdraw(Request $request, string $promotion, PromotionEditor $editor): RedirectResponse
    {
        $editor->withdraw($request->user(), $promotion);

        return back()->with('status', 'Oferta retirada.');
    }
}
