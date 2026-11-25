<?php

namespace App\Http\Requests;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** La app manda productos y cantidades; nunca precios. */
class NewSaleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // La web registra sus ventas por otra vía.
            'origin' => ['required', Rule::in(['in_person', 'whatsapp', 'wholesale'])],
            'customer' => ['required', 'array'],
            'customer.name' => ['required', 'string', 'max:120'],
            'customer.phone' => ['nullable', 'string', 'max:30'],
            'delivery' => ['required', 'array'],
            'delivery.type' => ['required', Rule::in(['pickup', 'delivery'])],
            'delivery.address' => ['nullable', 'required_if:delivery.type,delivery', 'string', 'max:300'],
            'delivery.promised_at' => ['required', 'date'],
            'payment_method' => ['required', Rule::in(['qr', 'transfer', 'cash'])],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'expected_total' => ['required', 'string', 'regex:'.Money::PATTERN],
        ];
    }

    public function attributes(): array
    {
        return [
            'origin' => 'cómo llegó la venta',
            'customer.name' => 'el nombre del cliente',
            'customer.phone' => 'el WhatsApp',
            'delivery.type' => 'el tipo de entrega',
            'delivery.address' => 'la dirección de entrega',
            'delivery.promised_at' => 'la fecha y hora de entrega',
            'payment_method' => 'la forma de pago',
            'lines' => 'los productos',
            'lines.*.product_id' => 'el producto',
            'lines.*.quantity' => 'la cantidad',
            'expected_total' => 'el total',
        ];
    }
}
