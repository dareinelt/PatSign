<?php

declare(strict_types=1);

namespace App\Support;

final class Validator
{
    /** @return array<string,string> */
    public function validateRequired(array $input, array $required): array
    {
        $errors = [];
        foreach ($required as $field) {
            if (!isset($input[$field]) || trim((string) $input[$field]) === '') {
                $errors[$field] = 'Pflichtfeld fehlt.';
            }
        }

        return $errors;
    }
}
