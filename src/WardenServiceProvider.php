<?php

declare(strict_types=1);

namespace Warden;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class WardenServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/warden.php', 'warden');

        $this->app->singleton(WardenManager::class, fn (Application $app): WardenManager => new WardenManager(
            (array) $app['config']->get('warden.schemas', [])
        ));

        /* Warden ships no default resolver; the consumer must configure one. */
        $this->app->bind(RuleResolver::class, function (Application $app): RuleResolver {
            $resolverClass = $app['config']->get('warden.rule_resolver');

            if ($resolverClass === null) {
                throw new RuntimeException(
                    'No Warden rule resolver configured. Set warden.rule_resolver to a class implementing '.RuleResolver::class.'.'
                );
            }

            return $app->make($resolverClass);
        });
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('warden', WardenMiddleware::class);

        /* Reachability guards. Mode and match mode live in the alias, so the
           route string after the colon is only the schema key and abilities —
           an ability may safely be named "any" or "all". */
        $router->aliasMiddleware('warden.could-ever', Middleware\CouldEverMiddleware::class);
        $router->aliasMiddleware('warden.could-ever.any', Middleware\CouldEverAnyMiddleware::class);
        $router->aliasMiddleware('warden.always', Middleware\AlwaysMiddleware::class);
        $router->aliasMiddleware('warden.always.any', Middleware\AlwaysAnyMiddleware::class);
        $router->aliasMiddleware('warden.never', Middleware\NeverMiddleware::class);
        $router->aliasMiddleware('warden.never.any', Middleware\NeverAnyMiddleware::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/warden.php' => $this->app->configPath('warden.php'),
            ], 'warden-config');
        }
    }
}
