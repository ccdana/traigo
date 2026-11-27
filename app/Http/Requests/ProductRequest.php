<?php

namespace App\Http\Requests;

use App\Services\Store\StoreCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Un producto en el panel. El ITEM y los precios solo se escriben al darlo de
 * alta; después, los precios cambian con una propuesta.
 */
class ProductRequest extends FormRequest
{
    public const MONEY = ['regex:/^\d{1,9}(\.\d{1,2})?$/'];

    public function authorize(): bool
    {
        return $this->user()?->role->canManageCatalog() ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('item_code'))) {
            $this->merge(['item_code' => Str::upper(trim($this->input('item_code')))]);
        }
    }

    public function rules(): array
    {
        $details = [
            'name' => ['required', 'string', 'max:150'],
            'category_id' => ['bail', 'required', 'uuid', Rule::exists('categories', 'id_category')],
            'description' => ['nullable', 'string', 'max:2000'],
            'gift_audience' => ['nullable', Rule::in(array_keys(StoreCatalog::GIFT_AUDIENCES))],
        ];

        if (! $this->isMethod('POST')) {
            return $details;
        }

        return [
            ...$details,
            'item_code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9][A-Z0-9\-_. ]*$/', Rule::unique('products', 'item_code')],
            'price_unit_bs' => ['required', ...self::MONEY],
            'price_wholesale_bs' => ['nullable', 'required_with:wholesale_min_qty', ...self::MONEY],
            'wholesale_min_qty' => ['nullable', 'required_with:price_wholesale_bs', 'integer', 'min:2', 'max:100000'],
            'cost_bs' => ['nullable', ...self::MONEY],
            'commission_pct' => ['nullable', 'numeric', 'between:0,100', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
        ];
    }

    public function attributes(): array
    {
        return [
            'item_code' => 'el ITEM',
            'name' => 'el nombre',
            'category_id' => 'la categoría',
            'description' => 'la descripción',
            'gift_audience' => 'para quién es',
            'price_unit_bs' => 'el precio por unidad',
            'price_wholesale_bs' => 'el precio por mayor',
            'wholesale_min_qty' => 'el mínimo por mayor',
            'cost_bs' => 'el costo',
            'commission_pct' => 'la comisión',
        ];
    }

    public function messages(): array
    {
        return [
            'item_code.unique' => 'Ya hay un producto con ese ITEM.',
            'item_code.regex' => 'El ITEM solo admite letras, números, guiones, puntos y espacios.',
            'price_unit_bs.regex' => 'Escribe el precio con punto para los decimales, por ejemplo 87.50.',
            'price_wholesale_bs.regex' => 'Revisa el precio por mayor.',
            'cost_bs.regex' => 'Revisa el costo.',
        ];
    }
}
