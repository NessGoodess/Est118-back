<?php

namespace App\Exceptions;

use RuntimeException;

class GuardianCurpConflictException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $canonical
     * @param  array<string, mixed>|null  $draft
     * @param  array<string, mixed>|null  $pending
     */
    public function __construct(
        public readonly array $canonical,
        public readonly ?array $draft = null,
        public readonly ?array $pending = null,
        string $message = 'Esa CURP ya pertenece a otro tutor registrado.',
    ) {
        parent::__construct($message);
    }
}
