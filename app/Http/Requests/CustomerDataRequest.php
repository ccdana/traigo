<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Los datos de un cliente, solo lo necesario para comprar: nombre, apellido,
 * WhatsApp y correo. Sirve para crear la cuenta y para editarla.
 */
class CustomerDataRequest extends FormRequest
{
    use NormalizesPhone {
        prepareForValidation as normalizePhone;
    }

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizePhone();

        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        $customer = $this->user('customer');

        $rules = [
            'name' => ['required', 'string', 'max:80'],
            'lastname' => ['required', 'string', 'max:80'],
            'phone' => ['required', 'string', 'regex:'.self::PHONE_PATTERN],
            'email' => [
                'required', 'string', 'email', 'max:150',
                Rule::unique('users', 'email')->ignore($customer?->getKey(), 'id_user'),
            ],
        ];

        if (! $customer) {
            return [...$rules, 'password' => ['required', 'string', 'confirmed', Password::min(8)]];
        }

        // Cambiar el correo permite recuperar la cuenta: pide la contraseña.
        return [
            ...$rules,
            'current_password' => [
                Rule::requiredIf($this->input('email') !== mb_strtolower((string) $customer->email)),
                'nullable', 'current_password:customer',
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'tu nombre',
            'lastname' => 'tu apellido',
            'phone' => 'tu WhatsApp',
            'email' => 'tu correo',
            'password' => 'la contraseña',
            'current_password' => 'tu contraseña actual',
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Escribe tu número de WhatsApp, solo con dígitos.',
            'email.unique' => 'Ya hay una cuenta con ese correo. Ingresa o recupera tu contraseña.',
            'current_password.required' => 'Escribe tu contraseña actual para cambiar el correo.',
        ];
    }
}
