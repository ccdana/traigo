<?php

namespace App\Http\Requests\Concerns;

/** «+591 712-34567» y «71234567» son el mismo WhatsApp. */
trait NormalizesPhone
{
    /** Dígitos, con `+` opcional. */
    public const PHONE_PATTERN = '/^\+?\d{7,15}$/';

    protected function prepareForValidation(): void
    {
        $phone = $this->input('phone');

        if (is_string($phone)) {
            $digits = preg_replace('/[\s\-().]/', '', $phone);
            $this->merge(['phone' => preg_replace('/^\+?591(?=\d{8}$)/', '', $digits)]);
        }
    }
}
