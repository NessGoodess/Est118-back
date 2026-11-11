<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when linking/merging a tutor that already exceeds the multi-student threshold
 * without an explicit confirm_multi_student flag.
 */
class GuardianMultiStudentConfirmationException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly array $context,
        string $message = 'Este tutor ya está ligado a varios alumnos. Confirma para continuar.',
    ) {
        parent::__construct($message);
    }
}
