<?php

namespace MiPaymentChoice\Cashier;

use Illuminate\Support\ServiceProvider;
use MiPaymentChoice\Cashier\Services\ApiClient;
use MiPaymentChoice\Cashier\Services\TokenService;
use MiPaymentChoice\Cashier\Services\QuickPaymentsService;

class CashierServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/mipaymentchoice.php',
            'mipaymentchoice'
        );

        $this->app->singleton(ApiClient::class, function ($app) {
            return new ApiClient(
                config('mipaymentchoice.username'),
                config('mipaymentchoice.password'),
                config('mipaymentchoice.base_url')
            );
        });

        $this->app->singleton(TokenService::class, function ($app) {
            return new TokenService(
                $app->make(ApiClient::class),
                config('mipaymentchoice.merchant_key')
            );
        });

        $this->app->singleton(QuickPaymentsService::class, function ($app) {
            return new QuickPaymentsService(
                $app->make(ApiClient::class),
                config('mipaymentchoice.merchant_key'),
                config('mipaymentchoice.quickpayments_key')
            );
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/mipaymentchoice.php' => config_path('mipaymentchoice.php'),
            ], 'mipaymentchoice-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'mipaymentchoice-migrations');
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
