<?php

namespace App\Providers;

use App\Exceptions\PipefyApiException;
use App\Services\PipefyService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PipefyService::class, function ($app) {
            $config = config('services.pipefy');

            if (empty($config['client_id']) || empty($config['client_secret'])) {
                throw PipefyApiException::missingToken();
            }

            return new PipefyService(
                $config['client_id'],
                $config['client_secret'],
                $config['token_url'],
                $config['endpoint'],
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
