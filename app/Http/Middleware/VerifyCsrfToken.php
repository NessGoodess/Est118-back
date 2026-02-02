<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        'api/admissions/pre-enrollment',
        'api/admissions/status',
        'api/admissions/public/folio/*', // Rutas públicas con URLs firmadas
    ];
}

