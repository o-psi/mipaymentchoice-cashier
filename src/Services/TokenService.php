<?php

namespace MiPaymentChoice\Cashier\Services;

use MiPaymentChoice\Cashier\Exceptions\ApiException;

class TokenService
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
     * @var int
     */
    protected $merchantKey;

    /**
     * Create a new token service instance.
     *
     * @param  \MiPaymentChoice\Cashier\Services\ApiClient  $api
     * @param  int  $merchantKey
     * @return void
     */
    public function __construct(ApiClient $api, $merchantKey)
    {
        $this->api = $api;
        $this->merchantKey = $merchantKey;
    }

    // ==================== Card Token Methods ====================

    /**
     * Create a card token.
     *
     * @param  array  $cardDetails
     * @param  int|null  $customerKey
     * @param  string  $tokenFormat
     * @return array
     * @throws ApiException
     */
    public function createCardToken(array $cardDetails, ?int $customerKey = null, string $tokenFormat = 'Uid'): array
    {
        $payload = [
            'MerchantKey' => (int) $this->merchantKey,
            'CardNumber' => $cardDetails['number'],
            'ExpirationDate' => sprintf(
                '%02d%02d',
                $cardDetails['exp_month'],
                substr($cardDetails['exp_year'], -2)
            ),
            'TokenFormat' => $tokenFormat,
        ];

        if ($customerKey) {
            $payload['CustomerKey'] = $customerKey;
        }

        if (isset($cardDetails['name'])) {
            $payload['NameOnCard'] = $cardDetails['name'];
        }

        if (isset($cardDetails['street'])) {
            $payload['StreetAddress'] = $cardDetails['street'];
        }

        if (isset($cardDetails['postal_code'])) {
            $payload['PostalCode'] = $cardDetails['postal_code'];
        }

        return $this->api->post("/merchants/{$this->merchantKey}/tokens/cards", $payload);
    }

    /**
     * Get a card token.
     *
     * @param  string  $token
     * @return array
     * @throws ApiException
     */
    public function getCardToken(string $token): array
    {
        return $this->api->get("/merchants/{$this->merchantKey}/tokens/cards/{$token}");
    }

    /**
     * Get all card tokens for the merchant.
     *
     * @param  array  $filters
     * @return array
     * @throws ApiException
     */
    public function getCardTokens(array $filters = []): array
    {
        return $this->api->get("/merchants/{$this->merchantKey}/tokens/cards", $filters);
    }

    /**
     * Update a card token (partial update).
     *
     * @param  string  $token
     * @param  array  $updates
     * @return array
     * @throws ApiException
     */
    public function updateCardToken(string $token, array $updates): array
    {
        $payload = array_merge(['Token' => $token], $updates);
        return $this->api->patch("/merchants/{$this->merchantKey}/tokens/cards/{$token}", $payload);
    }

    /**
     * Replace a card token (full replacement).
     *
     * @param  string  $token
     * @param  array  $cardDetails
     * @return array
     * @throws ApiException
     */
    public function replaceCardToken(string $token, array $cardDetails): array
    {
        $payload = [
            'MerchantKey' => (int) $this->merchantKey,
            'Token' => $token,
            'CardNumber' => $cardDetails['number'],
            'ExpirationDate' => sprintf(
                '%02d%02d',
                $cardDetails['exp_month'],
                substr($cardDetails['exp_year'], -2)
            ),
        ];

        if (isset($cardDetails['name'])) {
            $payload['NameOnCard'] = $cardDetails['name'];
        }

        if (isset($cardDetails['street'])) {
            $payload['StreetAddress'] = $cardDetails['street'];
        }

        if (isset($cardDetails['postal_code'])) {
            $payload['PostalCode'] = $cardDetails['postal_code'];
        }

        return $this->api->put("/merchants/{$this->merchantKey}/tokens/cards/{$token}", $payload);
    }

    /**
     * Delete one or more card tokens.
     *
     * @param  string|array  $tokens
     * @return void
     * @throws ApiException
     */
    public function deleteCardTokens($tokens): void
    {
        $tokenString = is_array($tokens) ? implode(',', $tokens) : $tokens;
        $this->api->delete("/merchants/{$this->merchantKey}/tokens/cards/{$tokenString}");
    }

    // ==================== Check Token Methods ====================

    /**
     * Create a check token.
     *
     * @param  array  $checkDetails
     * @param  int|null  $customerKey
     * @param  string  $tokenFormat
     * @return array
     * @throws ApiException
     */
    public function createCheckToken(array $checkDetails, ?int $customerKey = null, string $tokenFormat = 'Uid'): array
    {
        $payload = [
            'MerchantKey' => (int) $this->merchantKey,
            'AccountNumber' => $checkDetails['account_number'],
            'RoutingNumber' => $checkDetails['routing_number'],
            'TokenFormat' => $tokenFormat,
        ];

        if ($customerKey) {
            $payload['CustomerKey'] = $customerKey;
        }

        if (isset($checkDetails['name'])) {
            $payload['NameOnCheck'] = $checkDetails['name'];
        }

        if (isset($checkDetails['account_type'])) {
            $payload['AccountType'] = $checkDetails['account_type'];
        }

        if (isset($checkDetails['check_type'])) {
            $payload['CheckType'] = $checkDetails['check_type'];
        }

        return $this->api->post("/merchants/{$this->merchantKey}/tokens/checks", $payload);
    }

    /**
     * Get a check token.
     *
     * @param  string  $token
     * @return array
     * @throws ApiException
     */
    public function getCheckToken(string $token): array
    {
        return $this->api->get("/merchants/{$this->merchantKey}/tokens/checks/{$token}");
    }

    /**
     * Get all check tokens for the merchant.
     *
     * @param  array  $filters
     * @return array
     * @throws ApiException
     */
    public function getCheckTokens(array $filters = []): array
    {
        return $this->api->get("/merchants/{$this->merchantKey}/tokens/checks", $filters);
    }

    /**
     * Update a check token (partial update).
     *
     * @param  string  $token
     * @param  array  $updates
     * @return array
     * @throws ApiException
     */
    public function updateCheckToken(string $token, array $updates): array
    {
        $payload = array_merge(['Token' => $token], $updates);
        return $this->api->patch("/merchants/{$this->merchantKey}/tokens/checks/{$token}", $payload);
    }

    /**
     * Replace a check token (full replacement).
     *
     * @param  string  $token
     * @param  array  $checkDetails
     * @return array
     * @throws ApiException
     */
    public function replaceCheckToken(string $token, array $checkDetails): array
    {
        $payload = [
            'MerchantKey' => (int) $this->merchantKey,
            'Token' => $token,
            'AccountNumber' => $checkDetails['account_number'],
            'RoutingNumber' => $checkDetails['routing_number'],
        ];

        if (isset($checkDetails['name'])) {
            $payload['NameOnCheck'] = $checkDetails['name'];
        }

        if (isset($checkDetails['account_type'])) {
            $payload['AccountType'] = $checkDetails['account_type'];
        }

        if (isset($checkDetails['check_type'])) {
            $payload['CheckType'] = $checkDetails['check_type'];
        }

        return $this->api->put("/merchants/{$this->merchantKey}/tokens/checks/{$token}", $payload);
    }

    /**
     * Delete one or more check tokens.
     *
     * @param  string|array  $tokens
     * @return void
     * @throws ApiException
     */
    public function deleteCheckTokens($tokens): void
    {
        $tokenString = is_array($tokens) ? implode(',', $tokens) : $tokens;
        $this->api->delete("/merchants/{$this->merchantKey}/tokens/checks/{$tokenString}");
    }

    // ==================== General Token Methods ====================

    /**
     * Get all tokens (cards and checks) for a customer.
     *
     * @param  int  $customerKey
     * @return array
     * @throws ApiException
     */
    public function getCustomerTokens(int $customerKey): array
    {
        return $this->api->get("/merchants/{$this->merchantKey}/customers/{$customerKey}/tokens");
    }

    /**
     * Create a token from a PnRef (transaction reference).
     *
     * @param  int  $pnRef
     * @param  int|null  $customerKey
     * @param  string  $tokenFormat
     * @return array Returns CardToken and/or CheckToken
     * @throws ApiException
     */
    public function createTokenFromPnRef(int $pnRef, ?int $customerKey = null, string $tokenFormat = 'Uid'): array
    {
        $payload = [
            'MerchantKey' => (int) $this->merchantKey,
            'PnRef' => $pnRef,
            'TokenFormat' => $tokenFormat,
        ];

        if ($customerKey) {
            $payload['CustomerKey'] = $customerKey;
        }

        return $this->api->post("/merchants/{$this->merchantKey}/tokens", $payload);
    }

    // ==================== Legacy Methods (for backward compatibility) ====================

    /**
     * Create a payment token from card details (legacy method).
     *
     * @deprecated Use createCardToken() instead
     * @param  array  $cardDetails
     * @return array
     * @throws ApiException
     */
    public function createToken(array $cardDetails): array
    {
        return $this->createCardToken($cardDetails);
    }

    /**
     * Get token details (legacy method).
     *
     * @deprecated Use getCardToken() or getCheckToken() instead
     * @param  string  $token
     * @return array
     * @throws ApiException
     */
    public function getToken(string $token): array
    {
        // Try card token first, then check token
        try {
            return $this->getCardToken($token);
        } catch (ApiException $e) {
            return $this->getCheckToken($token);
        }
    }

    /**
     * Delete a token (legacy method).
     *
     * @deprecated Use deleteCardTokens() or deleteCheckTokens() instead
     * @param  string  $token
     * @return void
     * @throws ApiException
     */
    public function deleteToken(string $token): void
    {
        // Try to delete as card token first
        try {
            $this->deleteCardTokens($token);
        } catch (ApiException $e) {
            // If that fails, try as check token
            $this->deleteCheckTokens($token);
        }
    }
}
