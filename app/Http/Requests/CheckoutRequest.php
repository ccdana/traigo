<?php

namespace App\Http\Requests;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lo que se elige al confirmar un pedido. El nombre y el WhatsApp salen de la
 * cuenta, y los precios nunca llegan de aquí.
 */
class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'delivery_type' => ['required', Rule::in(['pickup', 'delivery'])],
            'address' => ['nullable', 'required_if:delivery_type,delivery', 'string', 'max:300'],
            // El total que la persona vio: si el precio cambió, se le avisa.
            'expected_total' => ['required', 'string', 'regex:'.Money::PATTERN],
            'client_uuid' => ['required', 'uuid'],
        ];
    }

    public function attributes(): array
    {
        return [
            'delivery_type' => 'la forma de entrega',
            'address' => 'la dirección de entrega',
        ];
    }

    public function messages(): array
    {
        return [
            'address.required_if' => 'Escribe la dirección para el delivery.',
        ];
    }

    /** @return array{delivery_type: string, address: ?string} */
    public function delivery(): array
    {
        return [
            'delivery_type' => $this->validated('delivery_type'),
            'address' => $this->validated('address'),
        ];
    }
}
