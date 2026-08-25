<?php

namespace App\Services;

use App\Models\AdmissionIdempotencyKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AdmissionIdempotencyService
{
    public const SCOPE_PUBLIC_STORE = 'admissions.public_store';

    public const SCOPE_ADMIN_STORE = 'admissions.admin_store';

    public const SCOPE_CONVERT = 'admissions.convert';

    public const SCOPE_BATCH = 'admissions.conversion_batch';

    /**
     * If the request carries an Idempotency-Key that was already processed,
     * return the stored response (or 409 on body mismatch). Otherwise null.
     */
    public function resolve(Request $request, string $scope): ?Response
    {
        $key = $this->extractKey($request);
        if ($key === null) {
            return null;
        }

        $ownerKey = $this->ownerKey($request);
        $requestHash = $this->hashRequest($request, $scope);

        $existing = AdmissionIdempotencyKey::query()
            ->where('scope', $scope)
            ->where('owner_key', $ownerKey)
            ->where('idempotency_key', $key)
            ->first();

        if (! $existing) {
            return null;
        }

        if ($existing->isExpired()) {
            $existing->delete();

            return null;
        }

        if (! hash_equals($existing->request_hash, $requestHash)) {
            return response()->json([
                'success' => false,
                'error_code' => 'idempotency_key_reuse',
                'message' => 'La clave de idempotencia ya se usó con un cuerpo diferente.',
            ], 409);
        }

        return response()->json($existing->response_body, $existing->response_status);
    }

    /**
     * Persist a successful (or stable conflict) response for later replay.
     */
    public function remember(Request $request, string $scope, Response $response): void
    {
        $key = $this->extractKey($request);
        if ($key === null || ! $this->shouldPersist($response)) {
            return;
        }

        $payload = $this->extractJsonPayload($response);
        if ($payload === null) {
            return;
        }

        $ownerKey = $this->ownerKey($request);
        $requestHash = $this->hashRequest($request, $scope);

        try {
            DB::transaction(function () use ($scope, $ownerKey, $key, $requestHash, $response, $payload, $request) {
                AdmissionIdempotencyKey::query()->updateOrCreate(
                    [
                        'scope' => $scope,
                        'owner_key' => $ownerKey,
                        'idempotency_key' => $key,
                    ],
                    [
                        'request_hash' => $requestHash,
                        'response_status' => $response->getStatusCode(),
                        'response_body' => $payload,
                        'user_id' => $request->user()?->id,
                        'expires_at' => now()->addHours(72),
                    ]
                );
            });
        } catch (\Throwable) {
            // Concurrent insert won the race; ignore — next call will replay.
        }
    }

    /**
     * @param  callable(): Response  $handler
     */
    public function run(Request $request, string $scope, callable $handler): Response
    {
        if ($early = $this->resolve($request, $scope)) {
            return $early;
        }

        $response = $handler();
        $this->remember($request, $scope, $response);

        return $response;
    }

    public function extractKey(Request $request): ?string
    {
        $header = trim((string) $request->header('Idempotency-Key', ''));
        if ($header !== '') {
            return $this->normalizeKey($header);
        }

        $bodyKey = $request->input('_idempotency_key');
        if (is_string($bodyKey) && trim($bodyKey) !== '') {
            return $this->normalizeKey($bodyKey);
        }

        return null;
    }

    private function normalizeKey(string $key): string
    {
        return substr(trim($key), 0, 128);
    }

    private function ownerKey(Request $request): string
    {
        $userId = $request->user()?->id;

        return $userId ? 'user:'.$userId : 'anon';
    }

    private function hashRequest(Request $request, string $scope): string
    {
        $payload = $request->except(['_idempotency_key']);

        if ($scope === self::SCOPE_CONVERT) {
            $routePre = $request->route('preEnrollment');
            $payload['_route_pre_enrollment_id'] = is_object($routePre)
                ? $routePre->id
                : $routePre;
        }

        $this->recursiveSort($payload);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function recursiveSort(array &$data): void
    {
        ksort($data);
        foreach ($data as &$value) {
            if (is_array($value)) {
                $this->recursiveSort($value);
            }
        }
    }

    private function shouldPersist(Response $response): bool
    {
        return in_array($response->getStatusCode(), [200, 201, 409], true);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractJsonPayload(Response $response): ?array
    {
        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);

            return is_array($data) ? $data : null;
        }

        $content = $response->getContent();
        if ($content === false || $content === '') {
            return null;
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : null;
    }
}
