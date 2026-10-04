<?php

namespace App\Http\Controllers\Print\Concerns;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

trait LogsPrintActions
{
    /**
     * @template T
     *
     * @param  array<string, mixed>  $context
     * @param  callable(): T  $callback
     * @param  (callable(T): array<string, mixed>)|null  $resultContext
     * @return T
     */
    protected function logPrintAction(string $action, array $context, callable $callback, ?callable $resultContext = null): mixed
    {
        $context = ['action' => $action, 'user_id' => Auth::id()] + $context;

        try {
            $result = $callback();
        } catch (ValidationException $e) {
            Log::warning("[print] {$action} rechazado", $context + [
                'errors' => $e->errors(),
            ]);
            throw $e;
        } catch (Throwable $e) {
            Log::error("[print] {$action} falló", $context + [
                'exception' => $e::class,
                'error' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]);
            throw $e;
        }

        Log::info("[print] {$action} ok", $context + ($resultContext ? $resultContext($result) : []));

        return $result;
    }
}
