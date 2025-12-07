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
     * This is handled automatically by not including the web middleware group
     * in our route registration. Instead, we apply only the necessary middleware
     * components individually (sessions, cookies, etc.) without CSRF.
     */
    protected function excludeFromCsrf(): void
    {
        // CSRF exclusion is handled in loadRoutes() by not using the 'web' middleware group
    }

    /**
     * Load the package routes.
     */
    protected function loadRoutes(): void
    {
        $routePrefix = config('refine.route_prefix', 'refine');

        // Register routes with necessary middleware but WITHOUT the 'web' group
        // This allows us to avoid CSRF verification while still having sessions, cookies, etc.
        \Illuminate\Support\Facades\Route::middleware([
                \Illuminate\Cookie\Middleware\EncryptCookies::class,
                \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
                \Illuminate\Session\Middleware\StartSession::class,
                \Illuminate\View\Middleware\ShareErrorsFromSession::class,
                \DevDojo\Refine\Http\Middleware\RefineMiddleware::class,
            ])
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

                // Get file history/backups
                \Illuminate\Support\Facades\Route::get('/history', [
                    \DevDojo\Refine\Http\Controllers\RefineController::class,
                    'history'
                ])->name('refine.history');

                // Get specific backup version contents
                \Illuminate\Support\Facades\Route::get('/version', [
                    \DevDojo\Refine\Http\Controllers\RefineController::class,
                    'getVersion'
                ])->name('refine.version');
            });
    }
}
