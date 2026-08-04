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
    private const MAX_REPORT_PAGES = 10000;

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
        ]);

        try {
            $batches = $this->fetchAllPages(
                '/api/reports/settlements',
                $query,
                'Batches',
                200,
                fn (array $batch): string => (string) ($batch['BatchId'] ?? $batch['BatchID'] ?? ''),
            );

            Log::info('MPC settlement batches fetched', [
                'begin' => $beginDate,
                'end' => $endDate,
                'count' => count($batches),
            ]);

            return $batches;
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
            $transactions = $this->fetchAllPages(
                "/api/reports/settlements/{$batchId}",
                [],
                'Transactions',
                500,
                fn (array $transaction): string => (string) ($transaction['TransactionId'] ?? $transaction['PnRef'] ?? ''),
            );

            Log::info('MPC batch detail fetched', [
                'batch_id' => $batchId,
                'count' => count($transactions),
            ]);

            return array_map(
                fn (array $transaction): array => $this->normalizeTransaction($transaction),
                $transactions,
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
        ]);

        try {
            return $this->fetchAllPages(
                '/api/reports/settlements/closed',
                $query,
                'Batches',
                200,
                fn (array $batch): string => (string) ($batch['BatchId'] ?? $batch['BatchID'] ?? ''),
            );
        } catch (ApiException $e) {
            Log::error('MPC closed batch list failed', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Fetch every page exposed by a reporting endpoint.
     *
     * The gateway occasionally returns an unwrapped list instead of the
     * documented collection envelope. In that case pages are requested until
     * a short page is returned. Repeated pages and inconsistent pagination
     * metadata fail closed so callers cannot mark partial data as complete.
     *
     * @param  array<string, mixed>  $query
     * @param  callable(array<string, mixed>): string  $identity
     * @return array<int, array<string, mixed>>
     *
     * @throws ApiException
     */
    private function fetchAllPages(
        string $endpoint,
        array $query,
        string $collectionKey,
        int $pageSize,
        callable $identity,
    ): array {
        $records = [];
        $seenRecords = [];
        $seenPages = [];
        $pageNumber = 1;

        while (true) {
            if ($pageNumber > self::MAX_REPORT_PAGES) {
                Log::warning('MPC report pagination reached the safety limit', [
                    'endpoint' => $endpoint,
                    'max_pages' => self::MAX_REPORT_PAGES,
                ]);

                throw new ApiException('MPC report pagination reached its safety limit.');
            }

            $response = $this->api->get($endpoint, array_merge($query, [
                'PageSize' => $pageSize,
                'PageNumber' => $pageNumber,
            ]));

            $pageRecords = $this->extractRecords($response, $collectionKey);
            $pageSignature = hash('sha256', serialize($pageRecords));

            if (isset($seenPages[$pageSignature])) {
                Log::warning('MPC report pagination returned a repeated page', [
                    'endpoint' => $endpoint,
                    'page' => $pageNumber,
                ]);

                throw new ApiException('MPC report pagination returned a repeated page.');
            }

            $seenPages[$pageSignature] = true;

            foreach ($pageRecords as $record) {
                $recordIdentity = $identity($record);
                $recordKey = $recordIdentity !== ''
                    ? 'id:'.$recordIdentity
                    : 'row:'.hash('sha256', serialize($record));

                if (isset($seenRecords[$recordKey])) {
                    continue;
                }

                $seenRecords[$recordKey] = true;
                $records[] = $record;
            }

            $pagination = $this->extractPagination($response);

            if ($pageRecords === []) {
                break;
            }

            if ($pagination === null) {
                if (count($pageRecords) < $pageSize) {
                    break;
                }

                $pageNumber++;

                continue;
            }

            $totalRecords = (int) ($pagination['TotalRecordCount'] ?? 0);
            $reportedPageSize = (int) ($pagination['PageSize'] ?? $pageSize);
            $currentPage = (int) ($pagination['CurrentPageNumber'] ?? $pageNumber);

            if ($currentPage !== $pageNumber) {
                Log::warning('MPC report pagination returned an unexpected page number', [
                    'endpoint' => $endpoint,
                    'requested_page' => $pageNumber,
                    'current_page' => $currentPage,
                ]);

                throw new ApiException('MPC report pagination returned an unexpected page number.');
            }

            if ($totalRecords > 0 && count($records) >= $totalRecords) {
                break;
            }

            if ($totalRecords <= 0 && count($pageRecords) < max(1, $reportedPageSize)) {
                break;
            }

            $expectedPages = $totalRecords > 0
                ? (int) ceil($totalRecords / max(1, $reportedPageSize))
                : null;

            if ($expectedPages !== null && $currentPage >= $expectedPages) {
                if (count($records) < $totalRecords) {
                    Log::warning('MPC report pagination ended with fewer unique records than reported', [
                        'endpoint' => $endpoint,
                        'reported' => $totalRecords,
                        'received' => count($records),
                    ]);

                    throw new ApiException('MPC report pagination returned fewer unique records than reported.');
                }

                break;
            }

            $pageNumber = $currentPage + 1;
        }

        return $records;
    }

    /**
     * Extract report records from either an envelope or an unwrapped list.
     *
     * @param  array<string|int, mixed>  $response
     * @return array<int, array<string, mixed>>
     */
    private function extractRecords(array $response, string $collectionKey): array
    {
        if (isset($response[$collectionKey]) && is_array($response[$collectionKey])) {
            return array_values(array_filter($response[$collectionKey], 'is_array'));
        }

        if (! array_is_list($response)) {
            return [];
        }

        $records = [];

        foreach ($response as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (isset($item[$collectionKey]) && is_array($item[$collectionKey])) {
                foreach ($item[$collectionKey] as $record) {
                    if (is_array($record)) {
                        $records[] = $record;
                    }
                }

                continue;
            }

            $records[] = $item;
        }

        return $records;
    }

    /**
     * Extract pagination metadata from an envelope or grouped response.
     *
     * @param  array<string|int, mixed>  $response
     * @return array<string, mixed>|null
     */
    private function extractPagination(array $response): ?array
    {
        if (isset($response['Pagination']) && is_array($response['Pagination'])) {
            return $response['Pagination'];
        }

        if (! array_is_list($response)) {
            return null;
        }

        $paginationRows = array_values(array_filter(
            array_map(
                fn (mixed $item): ?array => is_array($item) && isset($item['Pagination']) && is_array($item['Pagination'])
                    ? $item['Pagination']
                    : null,
                $response,
            ),
        ));

        if ($paginationRows !== []) {
            return [
                'CurrentPageNumber' => min(array_map(
                    fn (array $pagination): int => (int) ($pagination['CurrentPageNumber'] ?? 1),
                    $paginationRows,
                )),
                'PageSize' => array_sum(array_map(
                    fn (array $pagination): int => (int) ($pagination['PageSize'] ?? 0),
                    $paginationRows,
                )),
                'TotalRecordCount' => array_sum(array_map(
                    fn (array $pagination): int => (int) ($pagination['TotalRecordCount'] ?? 0),
                    $paginationRows,
                )),
            ];
        }

        return null;
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
