<?php

declare(strict_types=1);

namespace Warrant\Gate;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Warrant\Schema\WarrantSchema;
use Warrant\WarrantManager;

/**
 * Bridges Warrant into Laravel's Gate through a single {@see Gate::before} hook,
 * so every native authorization surface — `$user->can()`, `@can`,
 * `Gate::authorize`, and the `can:` route middleware — resolves Warrant
 * abilities. Abilities that no registered schema declares return null and fall
 * through untouched to the consumer's own policies.
 *
 * Calling conventions understood for the `$ability` + `$args` pair:
 *   can('view', $model)                          targeted row check
 *   can('approve', [$model, ['ctx' => 1]])       targeted + context
 *   can('create', Model::class)                  no-target via model class
 *   can('create', [Model::class, ['ctx' => 1]])  no-target via model class + context
 *   can('create', [Schema::class, ['ctx' => 1]]) no-target via schema class + context
 * ALL/ANY across abilities is native Laravel: can([...]) vs canAny([...]).
 */
final class WarrantGateBridge
{
    public function __construct(private readonly WarrantManager $manager)
    {
    }

    public function register(Gate $gate): void
    {
        /* Typed non-nullable, so Laravel skips this callback for guests (they
           fall through to policies). Widen to ?Authenticatable to opt in. */
        $gate->before(function (Authenticatable $user, string $ability, array $args = []): bool|Response|null {
            $call = $this->resolve($ability, $args);

            if ($call === null) {
                return null; // not a Warrant ability — let policies/other gates handle it
            }

            [$schemaClass, $ability, $target, $context] = $call;

            try {
                /* Reuse Warrant's own diagnose-and-throw path. */
                $schemaClass::authorize($ability, $target, $user, context: $context);

                return true; // granted (final — the policy is skipped)
            } catch (AuthorizationException $e) {
                /* Deny is FINAL for a Warrant-owned ability, carrying Warrant's
                   message and status. Return null instead to let a policy have
                   the final say over a Warrant deny. */
                return $e->toResponse();
            }
        });
    }

    /**
     * Map a Gate call to the schema, ability, optional target, and context — or
     * null when the call is not one a registered Warrant schema answers.
     *
     * @param  array<int|string, mixed>  $args
     * @return array{0: class-string<WarrantSchema>, 1: string, 2: Model|null, 3: array<string, mixed>}|null
     */
    private function resolve(string $ability, array $args): ?array
    {
        $schemaClass = null;
        $target = null;
        $context = [];

        /* $args carries a Model, a model/schema class-string, and/or a context
           array, in any order. A no-target check passes the class in place of an
           instance. */
        foreach ($args as $arg) {
            if ($arg instanceof Model) {
                $target = $arg;
            } elseif (is_string($arg)) {
                $schemaClass ??= $this->schemaFromClassString($arg);
            } elseif (is_array($arg)) {
                $context = $arg;
            }
        }

        /* A target instance names the schema authoritatively. */
        if ($target !== null) {
            $schemaClass = $this->manager->schemaForModelClassOrNull($target::class);
        }

        if ($schemaClass === null) {
            return null; // could not tie the call to a Warrant schema
        }

        /* Only claim abilities the schema actually declares (compiled or computed);
           everything else belongs to a policy. */
        if (!in_array($ability, $schemaClass::nonComputedAbilityNames(), true)
            && !$schemaClass::isComputedAbility($ability)) {
            return null;
        }

        return [$schemaClass, $ability, $target, $context];
    }

    /**
     * A Model or WarrantSchema class-string resolves to its schema class.
     *
     * @return class-string<WarrantSchema>|null
     */
    private function schemaFromClassString(string $class): ?string
    {
        if (is_a($class, WarrantSchema::class, true)) {
            return $class;
        }

        if (is_a($class, Model::class, true)) {
            return $this->manager->schemaForModelClassOrNull($class);
        }

        return null;
    }
}
