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
     * Load the package routes.
     */
    protected function loadRoutes(): void
    {
        $middleware = array_merge(
            config('refine.middleware', []),
            [\DevDojo\Refine\Http\Middleware\RefineMiddleware::class]
        );

        $routePrefix = config('refine.route_prefix', 'refine');

        // Register API routes
        \Illuminate\Support\Facades\Route::middleware($middleware)
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
