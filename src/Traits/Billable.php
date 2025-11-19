<?php

namespace MiPaymentChoice\Cashier\Traits;

use MiPaymentChoice\Cashier\Models\PaymentMethod;
use MiPaymentChoice\Cashier\Models\Subscription;
use MiPaymentChoice\Cashier\Services\ApiClient;
use MiPaymentChoice\Cashier\Services\QuickPaymentsService;
use MiPaymentChoice\Cashier\Services\TokenService;
use MiPaymentChoice\Cashier\Exceptions\PaymentFailedException;
use Illuminate\Database\Eloquent\Relations\HasMany;

trait Billable
{
    /**
     * Get the subscriptions for the user.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class)->orderBy('created_at', 'desc');
    }

    /**
     * Get the payment methods for the user.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class)->orderBy('created_at', 'desc');
    }

    /**
     * Get the default payment method for the user.
     *
     * @return \MiPaymentChoice\Cashier\Models\PaymentMethod|null
     */
    public function defaultPaymentMethod()
    {
        return $this->paymentMethods()->where('is_default', true)->first();
    }

    /**
     * Determine if the user has a payment method.
     *
     * @return bool
     */
    public function hasPaymentMethod(): bool
    {
        return $this->paymentMethods()->count() > 0;
    }

    /**
     * Determine if the user has a default payment method.
     *
     * @return bool
     */
    public function hasDefaultPaymentMethod(): bool
    {
        return $this->defaultPaymentMethod() !== null;
    }

    /**
     * Get the MiPaymentChoice customer ID.
     *
     * @return string|null
     */
    public function mpcCustomerId()
    {
        return $this->{config('mipaymentchoice.customer_columns.customer_id')};
    }

    /**
     * Determine if the user has a MiPaymentChoice customer ID.
     *
     * @return bool
     */
    public function hasMpcCustomerId(): bool
    {
        return !is_null($this->mpcCustomerId());
    }

    /**
     * Create a MiPaymentChoice customer for the user.
     *
     * @param  array  $options
     * @return $this
     */
    public function createAsMpcCustomer(array $options = [])
    {
        $api = app(ApiClient::class);

        $response = $api->post('/api/customers', array_merge([
            'Name' => $this->name ?? $this->email,
            'Email' => $this->email,
        ], $options));

        if (isset($response['CustomerId'])) {
            $this->{config('mipaymentchoice.customer_columns.customer_id')} = $response['CustomerId'];
            $this->save();
        }

        return $this;
    }

    /**
     * Add a payment method to the user.
     *
     * @param  string  $token
     * @param  array  $options
     * @return \MiPaymentChoice\Cashier\Models\PaymentMethod
     */
    public function addPaymentMethod(string $token, array $options = []): PaymentMethod
    {
        if (!$this->hasMpcCustomerId()) {
            $this->createAsMpcCustomer();
        }

        $isDefault = $options['default'] ?? !$this->hasPaymentMethod();

        $paymentMethod = $this->paymentMethods()->create([
            'mpc_token' => $token,
            'type' => $options['type'] ?? 'card',
            'last_four' => $options['last_four'] ?? null,
            'brand' => $options['brand'] ?? null,
            'is_default' => $isDefault,
        ]);

        if ($isDefault) {
            $paymentMethod->makeDefault();
        }

        return $paymentMethod;
    }

    /**
     * Update the default payment method.
     *
     * @param  string|PaymentMethod  $paymentMethod
     * @return \MiPaymentChoice\Cashier\Models\PaymentMethod
     */
    public function updateDefaultPaymentMethod($paymentMethod): PaymentMethod
    {
        if (is_string($paymentMethod)) {
            $paymentMethod = $this->paymentMethods()->find($paymentMethod);
        }

        return $paymentMethod->makeDefault();
    }

    /**
     * Delete a payment method.
     *
     * @param  string|PaymentMethod  $paymentMethod
     * @return void
     */
    public function deletePaymentMethod($paymentMethod)
    {
        if (is_string($paymentMethod)) {
            $paymentMethod = $this->paymentMethods()->find($paymentMethod);
        }

        if ($paymentMethod) {
            $paymentMethod->delete();
        }
    }

    /**
     * Begin creating a new subscription.
     *
     * @param  string  $name
     * @param  string  $plan
     * @return \MiPaymentChoice\Cashier\SubscriptionBuilder
     */
    public function newSubscription(string $name, string $plan)
    {
        return new \MiPaymentChoice\Cashier\SubscriptionBuilder($this, $name, $plan);
    }

    /**
     * Get a subscription by name.
     *
     * @param  string  $name
     * @return \MiPaymentChoice\Cashier\Models\Subscription|null
     */
    public function subscription(string $name = 'default')
    {
        return $this->subscriptions()->where('name', $name)->first();
    }

    /**
     * Determine if the user is on trial.
     *
     * @param  string  $name
     * @param  string|null  $plan
     * @return bool
     */
    public function onTrial(string $name = 'default', $plan = null): bool
    {
        $subscription = $this->subscription($name);

        if (is_null($subscription)) {
            return false;
        }

        if (!is_null($plan) && $subscription->mpc_plan !== $plan) {
            return false;
        }

        return $subscription->onTrial();
    }

    /**
     * Determine if the user has a subscription.
     *
     * @param  string  $name
     * @param  string|null  $plan
     * @return bool
     */
    public function subscribed(string $name = 'default', $plan = null): bool
    {
        $subscription = $this->subscription($name);

        if (is_null($subscription)) {
            return false;
        }

        if (!$subscription->active()) {
            return false;
        }

        if (!is_null($plan) && $subscription->mpc_plan !== $plan) {
            return false;
        }

        return true;
    }

    /**
     * Charge the customer.
     *
     * @param  int  $amount
     * @param  array  $options
     * @return array
     * @throws PaymentFailedException
     */
    public function charge(int $amount, array $options = []): array
    {
        $api = app(ApiClient::class);

        $paymentMethod = $options['payment_method'] ?? $this->defaultPaymentMethod();

        if (!$paymentMethod) {
            throw new PaymentFailedException('No payment method available for charge.');
        }

        if ($paymentMethod instanceof PaymentMethod) {
            $token = $paymentMethod->mpc_token;
        } else {
            $token = $paymentMethod;
        }

        try {
            $response = $api->post('/api/v2/transaction', [
                'Amount' => $amount / 100, // Convert cents to dollars
                'Currency' => $options['currency'] ?? config('mipaymentchoice.currency'),
                'Token' => $token,
                'Description' => $options['description'] ?? null,
                'CustomerId' => $this->mpcCustomerId(),
            ]);

            return $response;
        } catch (\Exception $e) {
            throw new PaymentFailedException($e->getMessage());
        }
    }

    /**
     * Refund a charge.
     *
     * @param  string  $transactionId
     * @param  int|null  $amount
     * @return array
     */
    public function refund(string $transactionId, $amount = null): array
    {
        $api = app(ApiClient::class);

        $data = [
            'TransactionId' => $transactionId,
        ];

        if ($amount) {
            $data['Amount'] = $amount / 100;
        }

        return $api->post('/api/v2/refund', $data);
    }

    /**
     * Create a QuickPayments token from card details.
     *
     * @param  array  $cardDetails
     * @return string The QuickPayments token
     * @throws PaymentFailedException
     */
    public function createQuickPaymentsToken(array $cardDetails): string
    {
        try {
            $qpService = app(QuickPaymentsService::class);
            $response = $qpService->createQpToken($cardDetails);
            
            return $response['QuickPaymentsToken'] ?? '';
        } catch (\Exception $e) {
            throw new PaymentFailedException('Failed to create QuickPayments token: ' . $e->getMessage());
        }
    }

    /**
     * Create a QuickPayments token from check details.
     *
     * @param  array  $checkDetails
     * @return string The QuickPayments token
     * @throws PaymentFailedException
     */
    public function createQuickPaymentsTokenFromCheck(array $checkDetails): string
    {
        try {
            $qpService = app(QuickPaymentsService::class);
            $response = $qpService->createQpTokenFromCheck($checkDetails);
            
            return $response['QuickPaymentsToken'] ?? '';
        } catch (\Exception $e) {
            throw new PaymentFailedException('Failed to create QuickPayments token: ' . $e->getMessage());
        }
    }

    /**
     * Charge using a QuickPayments token (one-time use).
     *
     * @param  string  $qpToken
     * @param  int  $amount Amount in cents
     * @param  array  $options
     * @return array
     * @throws PaymentFailedException
     */
    public function chargeWithQuickPayments(string $qpToken, int $amount, array $options = []): array
    {
        try {
            $qpService = app(QuickPaymentsService::class);
            return $qpService->charge($qpToken, $amount / 100, $options);
        } catch (\Exception $e) {
            throw new PaymentFailedException('QuickPayments charge failed: ' . $e->getMessage());
        }
    }

    /**
     * Convert a QuickPayments token to a reusable token and save as payment method.
     *
     * @param  string  $qpToken
     * @param  array  $options
     * @return \MiPaymentChoice\Cashier\Models\PaymentMethod
     * @throws PaymentFailedException
     */
    public function addPaymentMethodFromQuickPayments(string $qpToken, array $options = []): PaymentMethod
    {
        try {
            $qpService = app(QuickPaymentsService::class);
            $response = $qpService->createTokenFromQpToken($qpToken);
            
            $token = $response['Token'] ?? null;
            
            if (!$token) {
                throw new PaymentFailedException('Failed to convert QuickPayments token to reusable token.');
            }

            return $this->addPaymentMethod($token, $options);
        } catch (\Exception $e) {
            throw new PaymentFailedException('Failed to add payment method from QuickPayments: ' . $e->getMessage());
        }
    }

    // ==================== Token Management Methods ====================

    /**
     * Create a card token and optionally save it as a payment method.
     *
     * @param  array  $cardDetails
     * @param  bool  $saveAsPaymentMethod
     * @param  array  $options
     * @return string|PaymentMethod
     * @throws PaymentFailedException
     */
    public function tokenizeCard(array $cardDetails, bool $saveAsPaymentMethod = false, array $options = [])
    {
        try {
            $tokenService = app(TokenService::class);
            $customerKey = $this->mpc_customer_id ?? null;
            
            $response = $tokenService->createCardToken($cardDetails, $customerKey);
            $token = $response['Token'] ?? null;

            if (!$token) {
                throw new PaymentFailedException('Failed to create card token.');
            }

            if ($saveAsPaymentMethod) {
                return $this->addPaymentMethod($token, array_merge($options, [
                    'type' => 'card',
                    'last_four' => substr($response['CardNumber'] ?? '', -4),
                    'brand' => $response['CardType'] ?? null,
                ]));
            }

            return $token;
        } catch (\Exception $e) {
            throw new PaymentFailedException('Card tokenization failed: ' . $e->getMessage());
        }
    }

    /**
     * Create a check token and optionally save it as a payment method.
     *
     * @param  array  $checkDetails
     * @param  bool  $saveAsPaymentMethod
     * @param  array  $options
     * @return string|PaymentMethod
     * @throws PaymentFailedException
     */
    public function tokenizeCheck(array $checkDetails, bool $saveAsPaymentMethod = false, array $options = [])
    {
        try {
            $tokenService = app(TokenService::class);
            $customerKey = $this->mpc_customer_id ?? null;
            
            $response = $tokenService->createCheckToken($checkDetails, $customerKey);
            $token = $response['Token'] ?? null;

            if (!$token) {
                throw new PaymentFailedException('Failed to create check token.');
            }

            if ($saveAsPaymentMethod) {
                return $this->addPaymentMethod($token, array_merge($options, [
                    'type' => 'check',
                    'last_four' => substr($response['AccountNumber'] ?? '', -4),
                ]));
            }

            return $token;
        } catch (\Exception $e) {
            throw new PaymentFailedException('Check tokenization failed: ' . $e->getMessage());
        }
    }

    /**
     * Get all tokens associated with this customer.
     *
     * @return array
     * @throws PaymentFailedException
     */
    public function getTokens(): array
    {
        if (!$this->hasMpcCustomerId()) {
            return [];
        }

        try {
            $tokenService = app(TokenService::class);
            return $tokenService->getCustomerTokens($this->mpc_customer_id);
        } catch (\Exception $e) {
            throw new PaymentFailedException('Failed to retrieve tokens: ' . $e->getMessage());
        }
    }

    /**
     * Update a card token.
     *
     * @param  string  $token
     * @param  array  $updates
     * @return array
     * @throws PaymentFailedException
     */
    public function updateCardToken(string $token, array $updates): array
    {
        try {
            $tokenService = app(TokenService::class);
            return $tokenService->updateCardToken($token, $updates);
        } catch (\Exception $e) {
            throw new PaymentFailedException('Failed to update card token: ' . $e->getMessage());
        }
    }

    /**
     * Update a check token.
     *
     * @param  string  $token
     * @param  array  $updates
     * @return array
     * @throws PaymentFailedException
     */
    public function updateCheckToken(string $token, array $updates): array
    {
        try {
            $tokenService = app(TokenService::class);
            return $tokenService->updateCheckToken($token, $updates);
        } catch (\Exception $e) {
            throw new PaymentFailedException('Failed to update check token: ' . $e->getMessage());
        }
    }

    /**
     * Delete a card token.
     *
     * @param  string  $token
     * @return void
     * @throws PaymentFailedException
     */
    public function deleteCardToken(string $token): void
    {
        try {
            $tokenService = app(TokenService::class);
            $tokenService->deleteCardTokens($token);
        } catch (\Exception $e) {
            throw new PaymentFailedException('Failed to delete card token: ' . $e->getMessage());
        }
    }

    /**
     * Delete a check token.
     *
     * @param  string  $token
     * @return void
     * @throws PaymentFailedException
     */
    public function deleteCheckToken(string $token): void
    {
        try {
            $tokenService = app(TokenService::class);
            $tokenService->deleteCheckTokens($token);
        } catch (\Exception $e) {
            throw new PaymentFailedException('Failed to delete check token: ' . $e->getMessage());
        }
    }

    /**
     * Create a token from a transaction reference (PnRef).
     *
     * @param  int  $pnRef
     * @return array Returns CardToken and/or CheckToken
     * @throws PaymentFailedException
     */
    public function tokenizeFromTransaction(int $pnRef): array
    {
        try {
            $tokenService = app(TokenService::class);
            $customerKey = $this->mpc_customer_id ?? null;
            
            return $tokenService->createTokenFromPnRef($pnRef, $customerKey);
        } catch (\Exception $e) {
            throw new PaymentFailedException('Failed to create token from transaction: ' . $e->getMessage());
        }
    }
}
