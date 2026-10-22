<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Exceptions\BusinessRuleException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * CDP Connect: the CDP investment system. Given an ID number it returns who the person is there,
 * a summary of their investments and the investments themselves (Customer 360 and customer search).
 * Read-only: nothing from CDP is stored here.
 */
class CdpConnectService
{
    /**
     * @return array{customer: mixed, summary: mixed, investments: list<mixed>}
     *
     * @throws BusinessRuleException CDP_* with the status the frontend should show.
     */
    public function customerInvestments(string $idNumber, ?string $idType = null, ?string $status = null): array
    {
        $baseUrl = (string) config('services.cdp_connect.base_url');
        $apiKey = (string) config('services.cdp_connect.api_key');

        if ($baseUrl === '' || $apiKey === '') {
            Log::error('[CdpConnect] Not configured: set CDP_CONNECT_BASE_URL and CDP_CONNECT_API_KEY.');

            throw new BusinessRuleException('CDP_NOT_CONFIGURED', 'CDP Connect is not configured on this environment.', 503);
        }

        $params = array_filter([
            'id_number' => trim($idNumber),
            'id_type' => $idType !== null ? strtolower(trim($idType)) : null,
            'status' => $status,
        ], fn ($value) => $value !== null && $value !== '');

        try {
            $response = Http::withHeaders([
                (string) config('services.cdp_connect.key_header') => $apiKey,
                'Accept' => 'application/json',
            ])->timeout((int) config('services.cdp_connect.timeout'))
                ->get(rtrim($baseUrl, '/').'/api/v1/external/customer-investments', $params);
        } catch (ConnectionException $e) {
            Log::error('[CdpConnect] Connection failed: '.$e->getMessage());

            throw new BusinessRuleException('CDP_UNREACHABLE', 'Unable to connect to CDP Connect.', 502);
        }

        if ($response->successful()) {
            $data = $response->json('data') ?? [];

            return [
                'customer' => $data['customer'] ?? null,
                'summary' => $data['summary'] ?? null,
                'investments' => $data['investments'] ?? [],
            ];
        }

        if ($response->status() === 404) {
            throw new BusinessRuleException('CDP_CUSTOMER_NOT_FOUND', (string) $response->json('message', 'Customer not found in CDP Connect.'), 404);
        }

        if ($response->status() === 422) {
            throw new BusinessRuleException('CDP_INVALID_REQUEST', (string) $response->json('message', 'CDP Connect rejected the request.'), 422);
        }

        // 401 and anything else: our key or their service is wrong; the client only needs to know it failed.
        Log::error('[CdpConnect] Unexpected response '.$response->status(), ['body' => $response->body()]);

        throw new BusinessRuleException('CDP_UNAVAILABLE', 'CDP Connect could not be reached right now.', 502);
    }
}
