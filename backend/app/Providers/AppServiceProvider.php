<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
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
        // Dev-environment fix: when served from behind a reverse proxy (e.g. GitHub
        // Codespaces port forwarding), the incoming Host header reflects the internal
        // address (localhost:8000), not the public URL, which breaks generated asset()
        // and route() URLs. Forcing the root URL from APP_URL fixes this and is harmless
        // in production too, where APP_URL is simply the real domain.
        if (config('app.url')) {
            URL::forceRootUrl(config('app.url'));
            if (str_starts_with(config('app.url'), 'https://')) {
                URL::forceScheme('https');
            }
        }
    }
}
