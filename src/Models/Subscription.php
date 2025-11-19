<?php

namespace MiPaymentChoice\Cashier\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    /**
     * The attributes that are not mass assignable.
     *
     * @var array
     */
    protected $guarded = [];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'trial_ends_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    /**
     * Get the user that owns the subscription.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(config('mipaymentchoice.model'), 'user_id');
    }

    /**
     * Determine if the subscription is active.
     *
     * @return bool
     */
    public function active(): bool
    {
        return $this->ends_at === null || $this->onGracePeriod();
    }

    /**
     * Determine if the subscription is within its trial period.
     *
     * @return bool
     */
    public function onTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    /**
     * Determine if the subscription is within its grace period after cancellation.
     *
     * @return bool
     */
    public function onGracePeriod(): bool
    {
        return $this->ends_at && $this->ends_at->isFuture();
    }

    /**
     * Determine if the subscription is cancelled.
     *
     * @return bool
     */
    public function cancelled(): bool
    {
        return $this->ends_at !== null;
    }

    /**
     * Determine if the subscription has ended.
     *
     * @return bool
     */
    public function ended(): bool
    {
        return $this->ends_at && $this->ends_at->isPast();
    }

    /**
     * Cancel the subscription at the end of the billing period.
     *
     * @return $this
     */
    public function cancel()
    {
        // Set ends_at to the end of the current billing period
        $this->ends_at = $this->ends_at ?? now()->addMonth();
        $this->save();

        return $this;
    }

    /**
     * Cancel the subscription immediately.
     *
     * @return $this
     */
    public function cancelNow()
    {
        $this->ends_at = now();
        $this->save();

        return $this;
    }

    /**
     * Resume a cancelled subscription.
     *
     * @return $this
     */
    public function resume()
    {
        if (!$this->onGracePeriod()) {
            throw new \LogicException('Unable to resume subscription that is not within grace period.');
        }

        $this->ends_at = null;
        $this->save();

        return $this;
    }

    /**
     * Get the model's mpc_contract_id attribute.
     *
     * @return string|null
     */
    public function getMpcContractIdAttribute()
    {
        return $this->attributes['mpc_contract_id'] ?? null;
    }
}
