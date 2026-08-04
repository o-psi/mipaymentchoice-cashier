<?php

declare(strict_types=1);

namespace FoleyBridgeSolutions\MiPaymentChoiceCashier\Services;

use FoleyBridgeSolutions\MiPaymentChoiceCashier\Exceptions\ApiException;
use Illuminate\Support\Facades\Log;

/**
 * Service for pulling MiPaymentChoice settlement reports.
 *
 * Wraps:
 *   GET /api/reports/settlements           — list batches by date range
 *   GET /api/reports/settlements/{batchId} — transaction detail for one batch
 *   GET /api/reports/settlements/closed    — list closed batches
 */
class ReportService
{
    public function __construct(protected ApiClient $api) {}

    /**
     * List settlement batches for a date range.
     *
     * @param  string  $beginDate  ISO-8601 or Y-m-d
     * @return array<int, array{BatchId: string, BatchDate: string, TransactionCount: int, SaleAmount: float, ReturnAmount: float, TotalAmount: float, MID: string}>
     *
     * @throws ApiException
     */
    public function listBatches(string $beginDate, ?string $endDate = null): array
    {
        $query = array_filter([
            'BeginDate' => $beginDate,
            'EndDate' => $endDate,
            'PageSize' => 200,
        ]);

        try {
            $response = $this->api->get('/api/reports/settlements', $query);

            Log::info('MPC settlement batches fetched', [
                'begin' => $beginDate,
                'end' => $endDate,
                'count' => count($response['Batches'] ?? $response),
            ]);

            return $response['Batches'] ?? (is_array($response) ? $response : []);
        } catch (ApiException $e) {
            Log::error('MPC settlement batch list failed', [
                'error' => $e->getMessage(),
                'begin' => $beginDate,
                'end' => $endDate,
            ]);

            throw $e;
        }
    }

    /**
     * Get transaction-level detail for a single settlement batch.
     *
     * @return array<int, array{TransactionId: string, Timestamp: string|null, PaymentType: string|null, TransactionType: string|null, Amount: float, Payer: string|null}>
     *
     * @throws ApiException
     */
    public function getBatchDetail(string $batchId): array
    {
        try {
            $response = $this->api->get("/api/reports/settlements/{$batchId}", ['PageSize' => 500]);

            Log::info('MPC batch detail fetched', [
                'batch_id' => $batchId,
                'count' => count($response['Transactions'] ?? $response),
            ]);

            return array_map(
                fn (array $transaction): array => $this->normalizeTransaction($transaction),
                $response['Transactions'] ?? (is_array($response) ? $response : []),
            );
        } catch (ApiException $e) {
            Log::error('MPC batch detail fetch failed', [
                'batch_id' => $batchId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * List closed (settled) batches — useful for final reconciliation.
     *
     * @throws ApiException
     */
    public function listClosedBatches(string $beginDate, ?string $endDate = null): array
    {
        $query = array_filter([
            'BeginDate' => $beginDate,
            'EndDate' => $endDate,
            'PageSize' => 200,
        ]);

        try {
            $response = $this->api->get('/api/reports/settlements/closed', $query);

            return $response['Batches'] ?? (is_array($response) ? $response : []);
        } catch (ApiException $e) {
            Log::error('MPC closed batch list failed', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Normalize the gateway's nested settlement transaction into the stable
     * reporting shape consumed by applications using this package.
     *
     * @param  array<string, mixed>  $transaction
     * @return array{TransactionId: string, Timestamp: string|null, PaymentType: string|null, TransactionType: string|null, Amount: float, Payer: string|null}
     */
    private function normalizeTransaction(array $transaction): array
    {
        $transactionType = $transaction['TransactionType'] ?? null;
        $amount = (float) ($transaction['CardDetail']['TotalAmount']
            ?? $transaction['CheckDetail']['CheckAmount']
            ?? $transaction['CashDetail']['CashAmount']
            ?? $transaction['Amount']
            ?? 0);

        if (is_string($transactionType) && in_array(strtolower($transactionType), ['return', 'refund', 'credit'], true)) {
            $amount = -abs($amount);
        }

        return [
            'TransactionId' => (string) ($transaction['TransactionId'] ?? $transaction['PnRef'] ?? ''),
            'Timestamp' => $transaction['TransactionTimestamp'] ?? $transaction['SettlementTimestamp'] ?? null,
            'PaymentType' => $transaction['PaymentType'] ?? null,
            'TransactionType' => $transactionType,
            'Amount' => $amount,
            'Payer' => $transaction['CardDetail']['NameOnCard']
                ?? $transaction['CheckDetail']['NameOnCheck']
                ?? $transaction['CustomerReference']
                ?? null,
        ];
    }
}
