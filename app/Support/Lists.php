<?php

namespace App\Support;

final class Lists
{
    /**
     * «Ailén y Alison», «Kim, Alison e Isaí».
     *
     * @param  list<string>  $items
     */
    public static function join(array $items): string
    {
        if (count($items) < 2) {
            return implode('', $items);
        }

        $last = array_pop($items);
        // Antes de un sonido «i» se dice «e».
        $and = preg_match('/^(i|hi(?![aeiouáéíóú]))/iu', $last) ? 'e' : 'y';

        return implode(', ', $items)." {$and} {$last}";
    }
}
