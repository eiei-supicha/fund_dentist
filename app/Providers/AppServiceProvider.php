<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

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
        if($this->app->environment('production')) { // <--- เช็คว่าเป็น production ไหม
            URL::forceScheme('https');
        }
        \Illuminate\Pagination\Paginator
            ::defaultView('vendor.pagination.default');
        \Illuminate\Pagination\Paginator
            ::defaultSimpleView('vendor.pagination.simple-default');
    }
}
