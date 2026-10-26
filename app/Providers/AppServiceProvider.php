<?php

namespace App\Providers;

use App\Http\Middleware\AuthenticateMerchant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('usage', function (Request $request) {
            $merchant = $request->attributes->get(AuthenticateMerchant::ATTRIBUTE);

            if ($merchant === null) {
                return Limit::perMinute(60)->by('usage:ip:'.$request->ip());
            }

            $limits = [
                Limit::perMinute(config('billing.usage.rate_limit_per_merchant'))
                    ->by('usage:merchant:'.$merchant->id),
            ];

            // The customer reference is read from the raw input, before validation, which is fine
            // for a throttle key. Hashing it keeps cache keys short and safe whatever the client sends.
            $customerRef = $request->input('customer_id');

            if (is_string($customerRef) && $customerRef !== '') {
                $limits[] = Limit::perMinute(config('billing.usage.rate_limit_per_customer'))
                    ->by('usage:merchant:'.$merchant->id.':customer:'.sha1($customerRef));
            }

            return $limits;
        });

        RateLimiter::for('merchant-reads', function (Request $request) {
            $merchant = $request->attributes->get(AuthenticateMerchant::ATTRIBUTE);

            return Limit::perMinute(config('billing.dashboard.rate_limit_per_minute'))
                ->by($merchant ? 'reads:merchant:'.$merchant->id : 'reads:ip:'.$request->ip());
        });
    }
}
