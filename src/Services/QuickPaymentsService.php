<?php

namespace MiPaymentChoice\Cashier\Services;

use MiPaymentChoice\Cashier\Exceptions\ApiException;

class QuickPaymentsService
{
    /**
     * The API client instance.
     *
     * @var \MiPaymentChoice\Cashier\Services\ApiClient
     */
    protected $api;

    /**
     * The merchant key.
     *
     * @var string
     */
    protected $merchantKey;

    /**
     * Create a new QuickPayments service instance.
     *
     * @param  \MiPaymentChoice\Cashier\Services\ApiClient  $api
     * @param  string  $merchantKey
     * @return void
     */
    public function __construct(ApiClient $api, $merchantKey)
    {
        $this->api = $api;
        $this->merchantKey = $merchantKey;
    }

    /**
     * Get the QuickPayments key for creating tokens.
     *
     * @return string
     * @throws ApiException
     */
    protected function getQuickPaymentsKey(): string
    {
        $response = $this->getMerchantKey();
        return $response['QuickPaymentsKey'] ?? '';
    }

    /**
     * Create a one-time use QuickPayments token from card data.
     *
     * This creates a short-lived token that can be used in a subsequent transaction.
     *
     * @param  array  $cardDetails
     * @param  string|null  $quickPaymentsKey
     * @return array Returns array with 'QuickPaymentsToken'
     * @throws ApiException
     */
    public function createQpToken(array $cardDetails, ?string $quickPaymentsKey = null): array
    {
        if (!$quickPaymentsKey) {
            $quickPaymentsKey = $this->getQuickPaymentsKey();
        }

        $cardData = [
            'CardNumber' => $cardDetails['number'],
            'ExpirationDate' => sprintf(
                '%02d%02d',
                $cardDetails['exp_month'],
                substr($cardDetails['exp_year'], -2)
            ),
        ];

        if (isset($cardDetails['cvc'])) {
            $cardData['Cvv'] = (int) $cardDetails['cvc'];
        }

        if (isset($cardDetails['name'])) {
            $cardData['NameOnCard'] = $cardDetails['name'];
        }

        if (isset($cardDetails['street'])) {
            $cardData['Street'] = $cardDetails['street'];
        }

        if (isset($cardDetails['zip_code'])) {
            $cardData['ZipCode'] = $cardDetails['zip_code'];
        }

        if (isset($cardDetails['phone'])) {
            $cardData['Phone'] = $cardDetails['phone'];
        }

        if (isset($cardDetails['email'])) {
            $cardData['Email'] = $cardDetails['email'];
        }

        if (isset($cardDetails['entry_mode'])) {
            $cardData['EntryMode'] = $cardDetails['entry_mode'];
        }

        $payload = [
            'QuickPaymentsKey' => $quickPaymentsKey,
            'CardData' => $cardData,
        ];

        return $this->api->post('/quickpayments/qp-tokens', $payload);
    }

    /**
     * Create a one-time use QuickPayments token from check data.
     *
     * @param  array  $checkDetails
     * @param  string|null  $quickPaymentsKey
     * @return array Returns array with 'QuickPaymentsToken'
     * @throws ApiException
     */
    public function createQpTokenFromCheck(array $checkDetails, ?string $quickPaymentsKey = null): array
    {
        if (!$quickPaymentsKey) {
            $quickPaymentsKey = $this->getQuickPaymentsKey();
        }

        $checkData = [
            'RoutingNumber' => $checkDetails['routing_number'],
            'AccountNumber' => $checkDetails['account_number'],
        ];

        if (isset($checkDetails['name'])) {
            $checkData['NameOnCheck'] = $checkDetails['name'];
        }

        if (isset($checkDetails['check_number'])) {
            $checkData['CheckNumber'] = $checkDetails['check_number'];
        }

        if (isset($checkDetails['check_type'])) {
            $checkData['CheckType'] = $checkDetails['check_type'];
        }

        if (isset($checkDetails['account_type'])) {
            $checkData['AccountType'] = $checkDetails['account_type'];
        }

        if (isset($checkDetails['sec_code'])) {
            $checkData['SECCode'] = $checkDetails['sec_code'];
        }

        if (isset($checkDetails['address'])) {
            $checkData['Address'] = $this->formatCheckAddress($checkDetails['address']);
        }

        $payload = [
            'QuickPaymentsKey' => $quickPaymentsKey,
            'CheckData' => $checkData,
        ];

        return $this->api->post('/quickpayments/qp-tokens', $payload);
    }

    /**
     * Create a reusable token from a QuickPayments token.
     *
     * Converts a one-time QP token into a reusable token for future transactions.
     *
     * @param  string  $qpToken
     * @param  string|null  $quickPaymentsKey
     * @param  string  $tokenFormat Format: 'Uid', 'Numeric', or 'Alphanumeric'
     * @return array Returns array with 'Token'
     * @throws ApiException
     */
    public function createTokenFromQpToken(string $qpToken, ?string $quickPaymentsKey = null, string $tokenFormat = 'Uid'): array
    {
        if (!$quickPaymentsKey) {
            $quickPaymentsKey = $this->getQuickPaymentsKey();
        }

        return $this->api->post('/quickpayments/tokens', [
            'QuickPaymentsKey' => $quickPaymentsKey,
            'QuickPaymentsToken' => $qpToken,
            'TokenFormat' => $tokenFormat,
        ]);
    }

    /**
     * Get the merchant's QuickPayments key.
     *
     * @return array Returns array with 'QuickPaymentsKey'
     * @throws ApiException
     */
    public function getMerchantKey(): array
    {
        return $this->api->get("/quickpayments/merchants/{$this->merchantKey}/keys");
    }

    /**
     * Create a new QuickPayments key for the merchant.
     *
     * Creates a new key if one doesn't already exist.
     *
     * @return array Returns array with 'QuickPaymentsKey'
     * @throws ApiException
     */
    public function createMerchantKey(): array
    {
        return $this->api->post("/quickpayments/merchants/{$this->merchantKey}/keys", [
            'MerchantKey' => (int) $this->merchantKey,
        ]);
    }

    /**
     * Delete the merchant's QuickPayments key.
     *
     * @return array Returns array with 'QuickPaymentsKey'
     * @throws ApiException
     */
    public function deleteMerchantKey(): array
    {
        return $this->api->delete("/quickpayments/merchants/{$this->merchantKey}/keys");
    }

    /**
     * Process a quick payment using a QP token.
     *
     * This is a convenience method that creates a transaction directly with a QP token.
     *
     * @param  string  $qpToken
     * @param  float  $amount
     * @param  array  $options
     * @return array
     * @throws ApiException
     */
    public function charge(string $qpToken, float $amount, array $options = []): array
    {
        $payload = [
            'Amount' => $amount,
            'Currency' => $options['currency'] ?? config('mipaymentchoice.currency'),
            'QpToken' => $qpToken,
        ];

        if (isset($options['description'])) {
            $payload['Description'] = $options['description'];
        }

        if (isset($options['reference'])) {
            $payload['Reference'] = $options['reference'];
        }

        if (isset($options['metadata'])) {
            $payload['Metadata'] = $options['metadata'];
        }

        return $this->api->post('/api/v2/transaction', $payload);
    }

    /**
     * Format check address for API.
     *
     * @param  array  $address
     * @return array
     */
    protected function formatCheckAddress(array $address): array
    {
        return [
            'StreetAddress1' => $address['line1'] ?? $address['street'] ?? null,
            'StreetAddress2' => $address['line2'] ?? null,
            'StreetAddress3' => $address['line3'] ?? null,
            'City' => $address['city'] ?? null,
            'StateOrProvinceCode' => $address['state'] ?? null,
            'PostalCode' => $address['zip'] ?? $address['postal_code'] ?? null,
            'CountryCode' => $address['country'] ?? 'USA',
        ];
    }
}
