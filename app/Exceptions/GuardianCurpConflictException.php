<?php

namespace App\Exceptions;

use RuntimeException;

class GuardianCurpConflictException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $canonical
     */
    public function __construct(
        public readonly array $canonical,
        string $message = 'Esa CURP ya pertenece a otro tutor registrado.',
    ) {
        parent::__construct($message);
    }
}
