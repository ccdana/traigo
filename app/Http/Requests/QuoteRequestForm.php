<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** «Pide tu cotización» de la página por mayor. */
class QuoteRequestForm extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'regex:/^\+?[\d\s\-()]{7,20}$/'],
            'message' => ['required', 'string', 'max:1000'],
            // Trampa para robots: una persona no ve este campo.
            'website' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'tu nombre o empresa', 'phone' => 'tu WhatsApp', 'message' => 'qué necesitas'];
    }

    public function messages(): array
    {
        return ['phone.regex' => 'Escribe un número de WhatsApp válido.'];
    }

    public function isSpam(): bool
    {
        return filled($this->validated('website'));
    }
}
