<?php

namespace Warrant\Guard\Concerns;

use Closure;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;
use Warrant\AbilityMatchMode;
use Warrant\Rules\WarrantRule;
use Warrant\Schema\WarrantDenialContext;
use Warrant\Schema\WarrantUngrantedContext;
use Warrant\WarrantAuthorizationException;
use Warrant\WarrantGate;

/**
 * Diagnosing *why* a denied check failed and turning that into the exception to
 * throw — the denial-message feature behind {@see \Warrant\Guard\WarrantGuardForSchema::authorize()}.
 *
 * Runs only on the denial path (after a normal check has already returned false),
 * so its extra queries never touch the grant path. It distinguishes being
 * *forbidden* by a `cannot` (surfacing that clause's
 * {@see WarrantRule::messageFor()}) from being *ungranted* (nothing forbade and
 * nothing granted — surfacing the schema's
 * {@see \Warrant\Schema\WarrantSchema::ungrantedDenialMessage()} hook).
 */
trait DiagnosesDenials
{
    /**
     * Diagnose why a check was denied and build the exception to throw.
     *
     * Two distinct causes, resolved in this precedence:
     *  1. **Forbidden** — a matching `cannot` rule blocked an ability. The
     *     earliest message-bearing such rule (abilities in requested order, rules
     *     in resolver order — implicit first) wins, since being actively forbidden
     *     with a reason is the most specific answer. A matching `cannot` with no
     *     message is a deliberate forbid and falls through to a generic 403 — it
     *     is *not* treated as "ungranted".
     *  2. **Ungranted** — an ability that no `cannot` forbade and no `can`
     *     granted. With no rule to name, the schema's
     *     {@see \Warrant\Schema\WarrantSchema::ungrantedDenialMessage()} hook is
     *     consulted with the whole gate and the ungranted subset.
     *
     * With a singular target the query is rebuilt with global scopes removed —
     * matching how the singular check itself reads the row (`getQuery()` returns
     * the base query before Eloquent applies scopes), so diagnosis never
     * disagrees with the decision it explains. With no target only global /
     * unconditional `cannot` rules can match; a targeted condition is forced
     * false without a row, exactly as in the check.
     *
     * Returns null when nothing is attributable (missing row, or every failing
     * ability was forbidden by a message-less `cannot` and the ungranted hook
     * declined), in which case the caller throws a generic exception.
     *
     * @param string|array<int, string> $abilities
     * @param array<string, mixed> $context
     */
    protected function diagnoseDenial(
        string|array $abilities,
        Model|string|int|null $target,
        AbilityMatchMode $matchMode,
        array $context = []
    ): ?Throwable
    {
        $abilities = $this->schema->normalizeAbilities($abilities);

        if ($abilities === []) {
            return null;
        }

        $gate = new WarrantGate($abilities, $matchMode);
        $context = $this->schema->resolveEffectiveContext($context);
        $ruleSet = $this->resolvedRuleSet();
        $compiler = $this->compiler();

        if ($target !== null) {
            /** @var Model $model */
            $model = new ($this->schema::model);
            $targetId = $target instanceof Model ? $target->getKey() : $target;
            $targetSqlId = $model->getQualifiedKeyName();
            $baseQuery = fn (): Builder => $model->newQueryWithoutScopes()->whereKey($targetId)->getQuery();

            // Nothing to blame if the row does not exist even without scopes.
            if (! $baseQuery()->exists()) {
                return null;
            }

            $targetModel = $target instanceof Model
                ? $target
                : $model->newQueryWithoutScopes()->whereKey($targetId)->first();

            /* What the *check* would have handed to a row condition, which is not
               the same thing as $targetModel above: that one is fetched when the
               caller passed only a key, and is for the message hooks. Diagnosis
               has to reproduce the decision, not improve on it — compile with the
               row a condition actually saw, or a condition that answered in PHP
               would be re-asked in SQL and could disagree, leaving nothing to
               blame and a generic 403 in place of the rule's own message. */
            $conditionModel = $target instanceof Model && $target->exists ? $target : null;
        } else {
            // No target: evaluate the ability/condition predicates against a bare
            // one-row query on the entity's connection, exactly like the no-target
            // check does. targetSqlId is null, so targeted conditions force false.
            $connection = $this->schema::model !== ''
                ? (new ($this->schema::model))->getConnection()
                : app('db')->connection();
            $targetSqlId = null;
            $baseQuery = fn (): Builder => $connection->query();
            $targetModel = null;
            $conditionModel = null;
        }

        // Which requested abilities individually fail on this row?
        $failedAbilities = [];
        foreach ($abilities as $ability) {
            $query = $baseQuery();
            $predicate = $this->buildAbilityConditionQuery(
                query: $query,
                targetSqlId: $targetSqlId,
                ability: $ability,
                ruleSet: $ruleSet,
                context: $context,
                targetModel: $conditionModel,
            );

            $granted = $query
                ->selectRaw('1')
                ->where(fn (Builder $where) => $where->addNestedWhereQuery($predicate))
                ->exists();

            if (! $granted) {
                $failedAbilities[] = $ability;
            }
        }

        if ($failedAbilities === []) {
            return null;
        }

        // Sort each failing ability into "forbidden by a cannot" vs "ungranted",
        // surfacing a message-bearing forbid the moment we find one (it outranks
        // every other source). A message-less forbid is remembered as the fallback
        // for the schema's forbiddenDenialMessage hook.
        $ungrantedAbilities = [];
        $forbiddenRule = null;
        foreach ($failedAbilities as $ability) {
            $anyCannotFired = false;

            foreach ($ruleSet->rules as $rule) {
                if (! $rule->deniesAbility($ability)) {
                    continue;
                }

                $query = $baseQuery();
                $predicate = $compiler->matchesCondition(
                    $this->user,
                    $query,
                    $rule->conditions,
                    $targetSqlId,
                    $conditionModel,
                    $context,
                );

                $fired = $query
                    ->selectRaw('1')
                    ->where(fn (Builder $where) => $where->addNestedWhereQuery($predicate))
                    ->exists();

                if (! $fired) {
                    continue;
                }

                $message = $rule->messageFor($ability);

                if ($message !== null) {
                    return $this->buildDenialException($rule, $ability, $message, $targetModel, $gate, $context);
                }

                $anyCannotFired = true;
                $forbiddenRule ??= $rule;
            }

            if (! $anyCannotFired) {
                $ungrantedAbilities[] = $ability;
            }
        }

        // A forbid (even message-less) is a more specific answer than "ungranted",
        // so the schema forbidden hook is consulted first; only if it declines does
        // an ungranted ability fall to the ungranted hook.
        if ($forbiddenRule !== null) {
            $forbidden = $this->buildForbiddenException($forbiddenRule, $targetModel, $gate, $context);

            if ($forbidden !== null) {
                return $forbidden;
            }
        }

        if ($ungrantedAbilities !== []) {
            return $this->buildUngrantedException($targetModel, $gate, $ungrantedAbilities, $context);
        }

        return null;
    }

    /**
     * The concrete gate abilities a `cannot` rule blocks — the gate intersected
     * with the rule's denied abilities, with `*` resolved to the whole gate so
     * the caller never sees a wildcard.
     *
     * @param array<int, string> $gateAbilities
     * @return array<int, string>
     */
    private function abilitiesBlockedByRule(WarrantRule $rule, array $gateAbilities): array
    {
        $cannotAbilities = $rule->cannotAbilities();

        if (in_array('*', $cannotAbilities, true)) {
            return $gateAbilities;
        }

        return array_values(array_intersect($gateAbilities, $cannotAbilities));
    }

    /**
     * The gate abilities this rule blocks that share $ability's denial message —
     * so a per-ability message's context lists only the abilities it explains,
     * not siblings a different clause denied. For a rule-level/blanket message
     * every blocked ability resolves to the same message, so this is the whole
     * blocked set.
     *
     * @param array<int, string> $gateAbilities
     * @return array<int, string>
     */
    private function abilitiesBlockedByRuleForMessage(WarrantRule $rule, array $gateAbilities, string $ability): array
    {
        $target = $rule->messageFor($ability);

        return array_values(array_filter(
            $this->abilitiesBlockedByRule($rule, $gateAbilities),
            static fn (string $blocked): bool => $rule->messageFor($blocked) === $target,
        ));
    }

    /**
     * Resolve a `cannot` rule's message into the Throwable to throw. A string is
     * wrapped in a {@see WarrantAuthorizationException}; a closure receives the
     * denial context and returns either a string (wrapped) or a Throwable (thrown
     * as-is). Any other closure return falls back to a generic denial (null).
     *
     * @param array<string, mixed> $context
     */
    private function buildDenialException(
        WarrantRule $rule,
        string $ability,
        string|Closure $message,
        ?Model $targetModel,
        WarrantGate $gate,
        array $context,
    ): ?Throwable
    {
        $denialContext = new WarrantDenialContext(
            user: $this->user,
            target: $targetModel,
            schema: $this->schema::class,
            context: $context,
            gate: $gate,
            rule: $rule,
            deniedAbilities: $this->abilitiesBlockedByRuleForMessage($rule, $gate->abilities, $ability),
        );

        if (is_string($message)) {
            return new WarrantAuthorizationException($message, $denialContext);
        }

        $result = $message($denialContext);

        if ($result instanceof Throwable) {
            return $result;
        }

        if (is_string($result)) {
            return new WarrantAuthorizationException($result, $denialContext);
        }

        return null;
    }

    /**
     * Consult the schema's forbidden-denial hook for a message-less `cannot` and
     * resolve its return into the Throwable to throw: a string is wrapped in a
     * {@see WarrantAuthorizationException}, a Throwable is thrown as-is, and null
     * falls through (the caller then tries the ungranted hook, else generic).
     *
     * @param array<string, mixed> $context
     */
    private function buildForbiddenException(
        WarrantRule $rule,
        ?Model $targetModel,
        WarrantGate $gate,
        array $context,
    ): ?Throwable
    {
        $denialContext = new WarrantDenialContext(
            user: $this->user,
            target: $targetModel,
            schema: $this->schema::class,
            context: $context,
            gate: $gate,
            rule: $rule,
            deniedAbilities: $this->abilitiesBlockedByRule($rule, $gate->abilities),
        );

        $result = $this->schema->forbiddenDenialMessage($denialContext);

        if ($result instanceof Throwable) {
            return $result;
        }

        return is_string($result) ? new WarrantAuthorizationException($result, $denialContext) : null;
    }

    /**
     * Consult the schema's ungranted-denial hook for a lack-of-grant denial and
     * resolve its return into the Throwable to throw: a string is wrapped in a
     * {@see WarrantAuthorizationException}, a Throwable is thrown as-is, and null
     * falls back to the generic denial.
     *
     * @param array<int, string> $ungrantedAbilities
     * @param array<string, mixed> $context
     */
    private function buildUngrantedException(
        ?Model $targetModel,
        WarrantGate $gate,
        array $ungrantedAbilities,
        array $context,
    ): ?Throwable
    {
        $result = $this->schema->ungrantedDenialMessage(new WarrantUngrantedContext(
            user: $this->user,
            target: $targetModel,
            schema: $this->schema::class,
            context: $context,
            gate: $gate,
            ungrantedAbilities: $ungrantedAbilities,
        ));

        if ($result instanceof Throwable) {
            return $result;
        }

        return is_string($result) ? new WarrantAuthorizationException($result) : null;
    }
}
