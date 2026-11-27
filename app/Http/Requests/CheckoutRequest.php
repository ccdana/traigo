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
            'latitude' => ['exclude_unless:delivery_type,delivery', 'required', 'numeric', 'between:-90,90'],
            'longitude' => ['exclude_unless:delivery_type,delivery', 'required', 'numeric', 'between:-180,180'],
            'reference' => ['exclude_unless:delivery_type,delivery', 'nullable', 'string', 'max:300'],
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
            'latitude' => 'la latitud del punto de entrega',
            'longitude' => 'la longitud del punto de entrega',
            'reference' => 'la referencia de entrega',
        ];
    }

    public function messages(): array
    {
        return [
            'address.required_if' => 'Escribe la dirección para el delivery.',
            'latitude.required' => 'Marca el punto de entrega en el mapa.',
            'longitude.required' => 'Marca el punto de entrega en el mapa.',
        ];
    }

    /** @return array{delivery_type: string, address: ?string, latitude: ?string, longitude: ?string, reference: ?string} */
    public function delivery(): array
    {
        return [
            'delivery_type' => $this->validated('delivery_type'),
            'address' => $this->validated('address'),
            'latitude' => $this->validated('latitude') === null ? null : number_format((float) $this->validated('latitude'), 7, '.', ''),
            'longitude' => $this->validated('longitude') === null ? null : number_format((float) $this->validated('longitude'), 7, '.', ''),
            'reference' => $this->validated('reference'),
        ];
    }
}
