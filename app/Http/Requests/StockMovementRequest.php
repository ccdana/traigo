<?php

namespace App\Http\Requests;

use App\Enums\MovementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Un movimiento registrado en el panel web. La cantidad se escribe sin signo. */
class StockMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role->canMoveStock() ?? false;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(array_column(MovementType::manual(), 'value'))],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'note' => ['nullable', 'required_if:type,adjustment_in,adjustment_out', 'string', 'max:500'],
            // La genera el formulario: enviarlo dos veces no repite el movimiento.
            'client_uuid' => ['required', 'uuid'],
        ];
    }

    public function attributes(): array
    {
        return [
            'type' => 'el tipo de movimiento',
            'quantity' => 'la cantidad',
            'note' => 'el motivo',
        ];
    }

    public function messages(): array
    {
        return ['note.required_if' => 'Escribe el motivo del ajuste.'];
    }

    /** La cantidad con el signo que le corresponde al tipo. */
    public function signedQuantity(): int
    {
        $quantity = $this->integer('quantity');

        return MovementType::from($this->string('type')->value())->isOutgoing() ? -$quantity : $quantity;
    }
}
