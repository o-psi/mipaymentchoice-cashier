<?php

namespace MiPaymentChoice\Cashier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentMethod extends Model
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
        'is_default' => 'boolean',
    ];

    /**
     * Get the user that owns the payment method.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(config('mipaymentchoice.model'), 'user_id');
    }

    /**
     * Mark this payment method as the default.
     *
     * @return $this
     */
    public function makeDefault()
    {
        // Unset all other payment methods as default
        $this->user->paymentMethods()->update(['is_default' => false]);

        // Set this one as default
        $this->is_default = true;
        $this->save();

        return $this;
    }
}
