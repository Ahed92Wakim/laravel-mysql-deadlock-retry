<?php

namespace MysqlDeadlocks\RetryHelper;

use Illuminate\Support\ServiceProvider;

class RetryServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->register(TransactionLoggingServiceProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
