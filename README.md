# MiPaymentChoice Cashier

A Laravel Cashier-style package for the MiPaymentChoice payment gateway. This package provides an expressive, fluent interface for managing subscription billing services, including payment methods, subscriptions, and one-time charges.

## Installation

Install the package via Composer:

```bash
composer require mipaymentchoice/cashier
```

### Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag=mipaymentchoice-config
```

Add your MiPaymentChoice credentials to your `.env` file:

```env
MIPAYMENTCHOICE_USERNAME=your_api_username
MIPAYMENTCHOICE_PASSWORD=your_api_password
MIPAYMENTCHOICE_MERCHANT_KEY=your_merchant_key
MIPAYMENTCHOICE_BASE_URL=https://gateway.mipaymentchoice.com
```

### Database Migrations

Publish and run the migrations:

```bash
php artisan vendor:publish --tag=mipaymentchoice-migrations
php artisan migrate
```

This will create:
- `subscriptions` table
- `payment_methods` table
- Add `mpc_customer_id` column to your `users` table

### Model Setup

Add the `Billable` trait to your User model:

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use MiPaymentChoice\Cashier\Traits\Billable;

class User extends Authenticatable
{
    use Billable;
}
```

## Usage

### QuickPayments

QuickPayments provides a fast way to process one-time payments without creating a full customer account. It uses short-lived tokens for security.

#### Creating a QuickPayments Token from Card

```php
$qpToken = $user->createQuickPaymentsToken([
    'number' => '4111111111111111',
    'exp_month' => 12,
    'exp_year' => 2025,
    'cvc' => 123,
    'name' => 'John Doe',
    'street' => '123 Main St',
    'zip_code' => '10001',
    'email' => 'john@example.com',
]);
```

#### Creating a QuickPayments Token from Check

```php
$qpToken = $user->createQuickPaymentsTokenFromCheck([
    'routing_number' => '123456789',
    'account_number' => '9876543210',
    'name' => 'John Doe',
    'check_type' => 'Personal',
    'account_type' => 'Checking',
    'sec_code' => 'WEB',
    'address' => [
        'street' => '123 Main St',
        'city' => 'New York',
        'state' => 'NY',
        'zip' => '10001',
        'country' => 'USA',
    ],
]);
```

#### Charging with QuickPayments Token

```php
try {
    $response = $user->chargeWithQuickPayments($qpToken, 5000, [ // Amount in cents
        'description' => 'One-time purchase',
        'currency' => 'USD',
    ]);
} catch (\MiPaymentChoice\Cashier\Exceptions\PaymentFailedException $e) {
    // Handle failed payment
}
```

#### Converting QuickPayments Token to Reusable Payment Method

If you want to save a QuickPayments token for future use:

```php
$paymentMethod = $user->addPaymentMethodFromQuickPayments($qpToken, [
    'default' => true,
    'last_four' => '4242',
    'brand' => 'visa',
]);
```

#### Managing QuickPayments Keys (Advanced)

```php
use MiPaymentChoice\Cashier\Services\QuickPaymentsService;

$qpService = app(QuickPaymentsService::class);

// Get your merchant's QuickPayments key
$response = $qpService->getMerchantKey();
$key = $response['QuickPaymentsKey'];

// Create a new QuickPayments key (if one doesn't exist)
$response = $qpService->createMerchantKey();

// Delete the QuickPayments key
$response = $qpService->deleteMerchantKey();
```

### Tokenization

The package provides comprehensive tokenization services for both card and check payments.

#### Tokenizing a Card

```php
// Create a card token and save it as a payment method
$paymentMethod = $user->tokenizeCard([
    'number' => '4111111111111111',
    'exp_month' => 12,
    'exp_year' => 2025,
    'name' => 'John Doe',
    'street' => '123 Main St',
    'postal_code' => '10001',
], true, [ // Second parameter saves as payment method
    'default' => true,
]);

// Or just create a token without saving
$token = $user->tokenizeCard([
    'number' => '4111111111111111',
    'exp_month' => 12,
    'exp_year' => 2025,
]);
```

#### Tokenizing a Check

```php
// Create a check token and save it as a payment method
$paymentMethod = $user->tokenizeCheck([
    'routing_number' => '123456789',
    'account_number' => '9876543210',
    'name' => 'John Doe',
    'account_type' => 'Checking', // Checking or Savings
    'check_type' => 'Personal', // Personal or Business
], true, [
    'default' => true,
]);
```

#### Managing Tokens

```php
// Get all tokens for the customer
$tokens = $user->getTokens();

// Update a card token (e.g., update expiration date)
$user->updateCardToken($token, [
    'ExpirationDate' => '1226',
    'PostalCode' => '10002',
]);

// Update a check token
$user->updateCheckToken($token, [
    'AccountType' => 'Savings',
]);

// Delete a token
$user->deleteCardToken($token);
$user->deleteCheckToken($token);
```

#### Creating Token from Transaction

After processing a transaction, you can create a reusable token from the transaction reference:

```php
$response = $user->tokenizeFromTransaction($pnRef);

if (isset($response['CardToken'])) {
    $cardToken = $response['CardToken']['Token'];
    $user->addPaymentMethod($cardToken, ['type' => 'card']);
}

if (isset($response['CheckToken'])) {
    $checkToken = $response['CheckToken']['Token'];
    $user->addPaymentMethod($checkToken, ['type' => 'check']);
}
```

#### Advanced Token Management

For direct access to the token service:

```php
use MiPaymentChoice\Cashier\Services\TokenService;

$tokenService = app(TokenService::class);

// Get all card tokens with filters
$cardTokens = $tokenService->getCardTokens([
    'CustomerKey' => 123,
    'PageSize' => 50,
    'PageNumber' => 1,
    'SortField' => 'Date',
    'SortDirection' => 'desc',
]);

// Get all check tokens
$checkTokens = $tokenService->getCheckTokens();

// Get a specific token
$cardToken = $tokenService->getCardToken($token);
$checkToken = $tokenService->getCheckToken($token);

// Replace a token (full update)
$tokenService->replaceCardToken($oldToken, [
    'number' => '4111111111111111',
    'exp_month' => 12,
    'exp_year' => 2026,
]);

// Delete multiple tokens at once
$tokenService->deleteCardTokens(['token1', 'token2', 'token3']);
```

### Payment Methods

#### Adding a Payment Method

You can add a payment method using a pre-existing token:

```php
$user->addPaymentMethod($token, [
    'default' => true,
    'last_four' => '4242',
    'brand' => 'visa',
    'type' => 'card', // or 'check'
]);
```

#### Retrieving Payment Methods

```php
// Get all payment methods
$paymentMethods = $user->paymentMethods;

// Get default payment method
$defaultPaymentMethod = $user->defaultPaymentMethod();

// Check if user has a payment method
if ($user->hasPaymentMethod()) {
    // ...
}
```

#### Updating the Default Payment Method

```php
$user->updateDefaultPaymentMethod($paymentMethodId);
```

#### Deleting a Payment Method

```php
$user->deletePaymentMethod($paymentMethodId);
```

### Subscriptions

#### Creating a Subscription

```php
use MiPaymentChoice\Cashier\SubscriptionBuilder;

$user->newSubscription('default', 'premium_plan')
    ->create($token, [
        'amount' => 9.99,
        'frequency' => 'Monthly',
        'description' => 'Premium Subscription',
    ]);
```

#### Creating a Subscription with a Trial

```php
// Trial for 14 days
$user->newSubscription('default', 'premium_plan')
    ->trialDays(14)
    ->create($token, ['amount' => 9.99]);

// Trial until a specific date
$user->newSubscription('default', 'premium_plan')
    ->trialUntil(now()->addMonth())
    ->create($token, ['amount' => 9.99]);
```

#### Checking Subscription Status

```php
// Check if user is subscribed
if ($user->subscribed('default')) {
    // ...
}

// Check if user is subscribed to a specific plan
if ($user->subscribed('default', 'premium_plan')) {
    // ...
}

// Check if user is on trial
if ($user->onTrial('default')) {
    // ...
}
```

#### Accessing Subscription Information

```php
$subscription = $user->subscription('default');

// Check if subscription is active
if ($subscription->active()) {
    // ...
}

// Check if subscription is cancelled
if ($subscription->cancelled()) {
    // ...
}

// Check if subscription has ended
if ($subscription->ended()) {
    // ...
}

// Check if subscription is on grace period
if ($subscription->onGracePeriod()) {
    // ...
}
```

#### Cancelling Subscriptions

```php
// Cancel at the end of the billing period
$user->subscription('default')->cancel();

// Cancel immediately
$user->subscription('default')->cancelNow();
```

#### Resuming Subscriptions

```php
$user->subscription('default')->resume();
```

### One-Time Charges

#### Charging a Customer

```php
try {
    $response = $user->charge(1000, [ // Amount in cents
        'description' => 'Product purchase',
        'currency' => 'USD',
    ]);
} catch (\MiPaymentChoice\Cashier\Exceptions\PaymentFailedException $e) {
    // Handle failed payment
    echo $e->getMessage();
}
```

#### Refunding a Charge

```php
// Full refund
$user->refund($transactionId);

// Partial refund
$user->refund($transactionId, 500); // Amount in cents
```

### Customers

#### Creating a Customer

The package automatically creates a MiPaymentChoice customer when needed, but you can also manually create one:

```php
$user->createAsMpcCustomer([
    'Name' => 'John Doe',
    'Email' => 'john@example.com',
]);
```

#### Checking Customer Status

```php
if ($user->hasMpcCustomerId()) {
    $customerId = $user->mpcCustomerId();
}
```

## API Services

### Token Service

```php
use MiPaymentChoice\Cashier\Services\TokenService;

$tokenService = app(TokenService::class);

// Create a token from card details
$response = $tokenService->createToken([
    'number' => '4111111111111111',
    'exp_month' => '12',
    'exp_year' => '2025',
    'cvc' => '123',
    'name' => 'John Doe',
    'address_line1' => '123 Main St',
    'address_city' => 'New York',
    'address_state' => 'NY',
    'address_zip' => '10001',
    'address_country' => 'US',
]);

// Get token details
$tokenDetails = $tokenService->getToken($token);

// Delete a token
$tokenService->deleteToken($token);
```

### API Client

For advanced usage, you can use the API client directly:

```php
use MiPaymentChoice\Cashier\Services\ApiClient;

$api = app(ApiClient::class);

// Make a POST request
$response = $api->post('/api/v2/transaction', [
    'Amount' => 10.00,
    'Currency' => 'USD',
    // ...
]);

// Make a GET request
$response = $api->get('/api/customers', ['CustomerId' => '123']);
```

## Exception Handling

The package throws the following exceptions:

- `MiPaymentChoice\Cashier\Exceptions\ApiException` - Generic API errors
- `MiPaymentChoice\Cashier\Exceptions\PaymentFailedException` - Payment processing errors

Example:

```php
use MiPaymentChoice\Cashier\Exceptions\PaymentFailedException;

try {
    $user->charge(1000);
} catch (PaymentFailedException $e) {
    Log::error('Payment failed: ' . $e->getMessage());
    $response = $e->getResponse(); // Get full API response
}
```

## Testing

For testing, use the MiPaymentChoice test credentials:

```env
MIPAYMENTCHOICE_USERNAME=mcnorthapi1
MIPAYMENTCHOICE_PASSWORD=MCGws6sP2
MIPAYMENTCHOICE_BASE_URL=https://gateway.mipaymentchoice.com
```

## License

This package is open-sourced software licensed under the MIT license.
