<?php

namespace Warrant;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Warrant\Facades\Warrant;
use Warrant\Guard\WarrantGuard;

/**
 * Add to an Authenticatable user model to use it as the one holding abilities.
 *
 * `$user->warrant()` returns a {@see WarrantGuard} bound to `$this`, equivalent
 * to `Warrant::guard($user)`. From there, `->forSchema(...)` and the
 * check/reachability helpers are available.
 *
 * The `hasAbilityOn` scope filters users by the abilities they hold on something
 * else:
 *
 *     User::query()->hasAbilityOn('edit', $document)->get();
 *
 * It sits alongside {@see HasWarrantSchema}'s `userHasAbility`, its mirror:
 * that one takes a user and keeps the rows the user can act on; this one takes
 * a row and keeps the users who can act on it.
 */
trait AuthorizesWithWarrant
{
    public function warrant(): WarrantGuard
    {
        return Warrant::guard($this);
    }

    /**
     * Scope the query to users who hold the given ability or abilities on
     * $target, named as a check's target is: a model, a
     * `[ModelClass|SchemaClass, $id]` tuple, or a model or schema class for
     * abilities with no target. It answers for every user at once, so the rules
     * it reads have to be the same for every user. See
     * {@see \Warrant\Guard\UsersFilter}.
     *
     * @param  string|array<int, string>  $abilities
     * @param  Model|string|array<int, mixed>  $target
     * @param  array<string, mixed>  $context
     * @param  string|null  $rowsAs  The name the users' rows go by in the query,
     *   when its `from` does not say.
     */
    public function scopeHasAbilityOn(
        EloquentBuilder $query,
        string|array $abilities,
        Model|string|array $target,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
        array $context = [],
        ?string $rowsAs = null,
    ): EloquentBuilder {
        return Warrant::filterUsers($query, $abilities, $target, $matchMode, $context, $rowsAs);
    }
}
