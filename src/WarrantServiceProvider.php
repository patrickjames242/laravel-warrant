<?php

declare(strict_types=1);

namespace Warrant;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class WarrantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/warrant.php', 'warrant');

        $this->app->singleton(WarrantManager::class, fn (Application $app): WarrantManager => new WarrantManager(
            (array) $app['config']->get('warrant.schemas', [])
        ));

        /* Warrant ships no default resolver; the consumer must configure one. */
        $this->app->bind(RuleResolver::class, function (Application $app): RuleResolver {
            $resolverClass = $app['config']->get('warrant.rule_resolver');

            if ($resolverClass === null) {
                throw new RuntimeException(
                    'No Warrant rule resolver configured. Set warrant.rule_resolver to a class implementing '.RuleResolver::class.'.'
                );
            }

            return $app->make($resolverClass);
        });
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('warrant', WarrantMiddleware::class);

        /* Reachability guards. Mode and match mode live in the alias, so the
           route string after the colon is only the schema key and abilities —
           an ability may safely be named "any" or "all". */
        $router->aliasMiddleware('warrant.could-ever', Middleware\CouldEverMiddleware::class);
        $router->aliasMiddleware('warrant.could-ever.any', Middleware\CouldEverAnyMiddleware::class);
        $router->aliasMiddleware('warrant.always', Middleware\AlwaysMiddleware::class);
        $router->aliasMiddleware('warrant.always.any', Middleware\AlwaysAnyMiddleware::class);
        $router->aliasMiddleware('warrant.never', Middleware\NeverMiddleware::class);
        $router->aliasMiddleware('warrant.never.any', Middleware\NeverAnyMiddleware::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/warrant.php' => $this->app->configPath('warrant.php'),
            ], 'warrant-config');
        }
    }
}
