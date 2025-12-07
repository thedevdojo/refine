<?php

namespace DevDojo\Refine;

use DevDojo\Refine\Services\BladeInstrumentation;
use Illuminate\Support\ServiceProvider;

class RefineServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Merge configuration
        $this->mergeConfigFrom(__DIR__ . '/../config/refine.php', 'refine');

        // Register the BladeInstrumentation service
        $this->app->singleton(BladeInstrumentation::class, function ($app) {
            return new BladeInstrumentation();
        });

        // Exclude Refine routes from CSRF verification
        $this->excludeFromCsrf();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Only activate if enabled and in local environment
        if (!config('refine.enabled') || app()->environment() !== 'local') {
            return;
        }

        // Publish configuration file
        $this->publishes([
            __DIR__ . '/../config/refine.php' => config_path('refine.php'),
        ], 'refine-config');

        // Load routes
        $this->loadRoutes();

        // Register Blade instrumentation
        $this->app->make(BladeInstrumentation::class)->register();
    }

    /**
     * Exclude Refine routes from CSRF verification.
     *
     * Note: In Laravel 11+, users need to manually add CSRF exclusion
     * in their bootstrap/app.php file:
     *
     * $middleware->validateCsrfTokens(except: ['refine/*']);
     *
     * This is documented in the installation guide.
     */
    protected function excludeFromCsrf(): void
    {
        // No automatic CSRF exclusion - users must configure manually
        // This is intentional for Laravel 11+ to make the setup explicit
    }

    /**
     * Load the package routes.
     */
    protected function loadRoutes(): void
    {
        $routePrefix = config('refine.route_prefix', 'refine');

        // Register routes with web middleware (CSRF exclusion is handled above)
        \Illuminate\Support\Facades\Route::middleware(['web', \DevDojo\Refine\Http\Middleware\RefineMiddleware::class])
            ->prefix($routePrefix)
            ->group(function () {
                // Status endpoint to verify Refine is working
                \Illuminate\Support\Facades\Route::get('/status', [
                    \DevDojo\Refine\Http\Controllers\RefineController::class,
                    'status'
                ])->name('refine.status');

                // Fetch source code
                \Illuminate\Support\Facades\Route::get('/fetch', [
                    \DevDojo\Refine\Http\Controllers\RefineController::class,
                    'fetch'
                ])->name('refine.fetch');

                // Save updated source code
                \Illuminate\Support\Facades\Route::post('/save', [
                    \DevDojo\Refine\Http\Controllers\RefineController::class,
                    'save'
                ])->name('refine.save');
            });
    }
}
