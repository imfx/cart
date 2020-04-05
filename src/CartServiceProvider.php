<?php

namespace Cart;

use Illuminate\Auth\Events\Logout;
use Illuminate\Session\SessionManager;
use Illuminate\Support\ServiceProvider;

class CartServiceProvider extends ServiceProvider
{

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind('cart', 'Cart\Cart');

        if ($this->app->runningInConsole()) {
            $this->publishResources();
        }

        if (! $this->app->configurationIsCached()) {
            $this->mergeConfigFrom(__DIR__ . '/../config/cart.php', 'cart');
        }

        $this->app['events']->listen(Logout::class, function () {
            if ($this->app['config']->get('cart.destroy_on_logout')) {
                $this->app->make(SessionManager::class)->forget('cart');
            }
        });
    }

    public function publishResources()
    {
        $this->publishes([
            __DIR__ . '/../config/cart.php' => config_path('cart.php')
        ], 'cart-config');

        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations')
        ], 'cart-migrations');
    }
}
