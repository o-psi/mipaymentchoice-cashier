<?php

namespace MiPaymentChoice\Cashier;

use Carbon\Carbon;
use MiPaymentChoice\Cashier\Exceptions\ApiException;
use MiPaymentChoice\Cashier\Models\Subscription;
use MiPaymentChoice\Cashier\Services\ApiClient;

class SubscriptionBuilder
{
    /**
     * The user that is subscribing.
     *
     * @var mixed
     */
    protected $user;

    /**
     * The name of the subscription.
     *
     * @var string
     */
    protected $name;

    /**
     * The plan the user is subscribing to.
     *
     * @var string
     */
    protected $plan;

    /**
     * The number of trial days to apply to the subscription.
     *
     * @var int|null
     */
    protected $trialDays;

    /**
     * The date and time the trial should expire.
     *
     * @var \Carbon\Carbon|null
     */
    protected $trialExpires;

    /**
     * The metadata to apply to the subscription.
     *
     * @var array
     */
    protected $metadata = [];

    /**
     * Create a new subscription builder instance.
     *
     * @param  mixed  $user
     * @param  string  $name
     * @param  string  $plan
     * @return void
     */
    public function __construct($user, string $name, string $plan)
    {
        $this->user = $user;
        $this->name = $name;
        $this->plan = $plan;
    }

    /**
     * Specify the number of days of the trial.
     *
     * @param  int  $trialDays
     * @return $this
     */
    public function trialDays(int $trialDays)
    {
        $this->trialDays = $trialDays;

        return $this;
    }

    /**
     * Specify the ending date of the trial.
     *
     * @param  \Carbon\Carbon  $trialExpires
     * @return $this
     */
    public function trialUntil(Carbon $trialExpires)
    {
        $this->trialExpires = $trialExpires;

        return $this;
    }

    /**
     * Skip the trial period.
     *
     * @return $this
     */
    public function skipTrial()
    {
        $this->trialDays = null;
        $this->trialExpires = null;

        return $this;
    }

    /**
     * Add metadata to the subscription.
     *
     * @param  array  $metadata
     * @return $this
     */
    public function withMetadata(array $metadata)
    {
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * Create the subscription.
     *
     * @param  string|null  $token
     * @param  array  $options
     * @return \MiPaymentChoice\Cashier\Models\Subscription
     * @throws ApiException
     */
    public function create($token = null, array $options = []): Subscription
    {
        if (!$this->user->hasMpcCustomerId()) {
            $this->user->createAsMpcCustomer();
        }

        // If a token is provided, add it as a payment method
        if ($token) {
            $this->user->addPaymentMethod($token, ['default' => true]);
        }

        // Ensure the user has a default payment method
        if (!$this->user->hasDefaultPaymentMethod()) {
            throw new ApiException('No payment method available to create subscription.');
        }

        $api = app(ApiClient::class);

        // Determine trial end date
        $trialEndsAt = $this->getTrialEndDate();

        try {
            // Create recurring billing contract with MiPaymentChoice
            $response = $api->post('/api/recurringbillingcontracts', array_merge([
                'CustomerId' => $this->user->mpcCustomerId(),
                'Token' => $this->user->defaultPaymentMethod()->mpc_token,
                'Amount' => $options['amount'] ?? 0,
                'Frequency' => $options['frequency'] ?? 'Monthly',
                'StartDate' => $trialEndsAt ? $trialEndsAt->toDateString() : now()->toDateString(),
                'Description' => $options['description'] ?? $this->name,
            ], $this->metadata));

            // Create local subscription record
            $subscription = $this->user->subscriptions()->create([
                'name' => $this->name,
                'mpc_plan' => $this->plan,
                'mpc_contract_id' => $response['ContractId'] ?? null,
                'trial_ends_at' => $trialEndsAt,
                'ends_at' => null,
            ]);

            return $subscription;
        } catch (\Exception $e) {
            throw new ApiException('Failed to create subscription: ' . $e->getMessage());
        }
    }

    /**
     * Get the trial end date.
     *
     * @return \Carbon\Carbon|null
     */
    protected function getTrialEndDate()
    {
        if ($this->trialExpires) {
            return $this->trialExpires;
        }

        if ($this->trialDays) {
            return now()->addDays($this->trialDays);
        }

        return null;
    }
}
