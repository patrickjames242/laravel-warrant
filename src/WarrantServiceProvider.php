<?php

declare(strict_types=1);

namespace Warrant;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Warrant\Gate\WarrantGateBridge;
use Warrant\Middleware\WarrantMiddleware;
use Warrant\Registry\SchemaRegistry;
use Warrant\Rules\RuleResolver;

final class WarrantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/warrant.php', 'warrant');

        $this->app->singleton(WarrantManager::class, fn (Application $app): WarrantManager => new WarrantManager(
            new SchemaRegistry((array) $app['config']->get('warrant.schemas', []))
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

        /* Resolve Warrant abilities through Laravel's Gate. Registered lazily so
           the config toggle is read once the Gate (and container config) exist. */
        $this->callAfterResolving(Gate::class, function (Gate $gate) {
            if ((bool) $this->app['config']->get('warrant.register_gate', true)) {
                (new WarrantGateBridge($this->app->make(WarrantManager::class)))->register($gate);
            }
        });

        $this->flushGuardsBetweenRequests();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/warrant.php' => $this->app->configPath('warrant.php'),
            ], 'warrant-config');
        }
    }

    /**
     * Drop Warrant's memoized guards at every long-lived-process boundary.
     *
     * The manager memoizes a guard per user, and each guard memoizes the rule set
     * it resolved. Under PHP-FPM that memo dies with the request; under Octane or
     * a queue worker the container is reused, so without this a worker would keep
     * answering from rules resolved for an earlier request — long after a role
     * change should have taken effect.
     *
     * The Octane events are named by class constant even though Warrant does not
     * depend on Octane: `::class` on a fully-qualified name is resolved at compile
     * time and never autoloads, so naming an absent class is safe — it yields the
     * string the dispatcher matches on, and no listener ever fires. The manager is
     * flushed only if something actually resolved it.
     */
    private function flushGuardsBetweenRequests(): void
    {
        $events = [
            \Laravel\Octane\Events\RequestTerminated::class,
            \Laravel\Octane\Events\TaskTerminated::class,
            \Illuminate\Queue\Events\JobProcessed::class,
            \Illuminate\Queue\Events\JobFailed::class,
        ];

        foreach ($events as $event) {
            $this->app['events']->listen($event, function (): void {
                if ($this->app->resolved(WarrantManager::class)) {
                    $this->app->make(WarrantManager::class)->flush();
                }
            });
        }
    }
}
