---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Errors & exceptions
description: Every exception Warrant throws — when, where, and the exact message.
sidebar:
  order: 6
---

Warrant fails **loudly**. A typo in a stored rule, a missing context key, or a
misconfigured schema throws rather than silently granting or denying. This is the
catalogue.

## When each error surfaces

| Stage | What's checked |
|---|---|
| **Parse time** | Rule syntax, binding consistency (`WarrantSyntaxException`) |
| **Compile / validate time** | Ability / condition / context-key names exist on the schema |
| **Check time** | Requested ability exists; required context present; user available |
| **Boot / reflection** | Schema registry uniqueness; condition method signatures |

## Syntax errors → `WarrantSyntaxException`

Thrown eagerly from the lexer/parser. Extends `RuntimeException` and carries
`$source`, `$offset`, `$sourceLine`, and `$sourceColumn`. The message includes the
line, column, and a caret:

```text
Reserved word 'can' cannot be used as a name; expected an ability name. (line 1, column 21)

    if is_self they can can
                        ^
```

Representative messages:

- `Unexpected character %s.`
- `Unterminated string literal.`
- `Invalid escape sequence "\%s"; only \' and \\ are allowed.`
- `Expected 'context' after '@'.` / `Expected a context key after '@context'.`
- `Expected 'can' or 'cannot' after 'they'.`
- `Expected at least one 'they can ...' or 'they cannot ...' clause.`
- `Expected ')' to close the group.` / `Expected ')' to close the condition arguments.`
- `Reserved word '%s' cannot be used as a name; expected %s.`
- `Expected a rule.` / `Expected a single rule but found multiple.`

### Binding errors (also `WarrantSyntaxException`)

- `Cannot mix named and positional bindings.`
- `No binding provided for ":%s".`
- `More positional placeholders (?) than bindings provided.`
- `%d positional binding(s) were provided but never used.`
- `Binding(s) provided but never used: %s.`

## Validation errors → `InvalidArgumentException`

Thrown when a rule set is validated/compiled against a schema:

- `Ability [%s] is not declared by the schema.`
- `Condition [%s] is not declared by the schema.`
- `Context key [%s] is not declared by the schema.`

Attaching a denial message to a rule that has no `theyCannot` clause is also
rejected here — only a `cannot` rule may carry a `withDenialMessage()`. See
[Denial messages](/guides/denial-messages/).

`fromRules` / `validateAll` type-guard their inputs:

- `fromRules expects WarrantRule or WarrantRuleBuilder instances, got %s.`
- `validateAll expects WarrantRuleSet instances, got %s.`

:::note[Two distinct "unknown ability" messages]
The message above (*"is not declared by the schema"*) comes from validating a
**rule set**. A *different* one — *"Ability [%s] is not defined on schema [%s]."* —
is thrown at **check time** when the ability you *request* (e.g.
`userHasAbilities('destroy')`) isn't declared. Same root cause, different call
site.
:::

## Condition / reflection errors → `InvalidArgumentException`

Thrown lazily the first time a schema's conditions are reflected:

- `Condition method [%s::%s] must not declare duplicate condition attributes.`
- `Condition method [%s::%s] cannot declare both #[RowCondition] and #[GlobalCondition].`
- `Condition method [%s::%s] must resolve to a non-empty condition key.`
- `Condition method [%s::%s] must accept exactly one [%s] parameter.` — wrong context type **or** an extra parameter.
- `Schema [%s] is a schema with no model and does not support targeted checks; use a no-target check instead.`

## Applying a condition

From the condition resolver:

- `BadMethodCallException` — `Condition [%s] is not defined on schema [%s].`
- `InvalidArgumentException` — `Condition [%s] on schema [%s] requires a target SQL id.` (a row condition run with no target)

## Context errors → `InvalidArgumentException`

```text
Schema [%s] requires context key(s) [%s]; supply them at the check or via defaultContext().
```

See [Check-time context](/guides/context/#required-vs-optional). Note that an
[*optional* key](/guides/context/#missing-optional-context) that's absent doesn't
throw — it's passed to its condition as `null` (standard SQL logic then applies,
which is fail-closed).

## Registry errors → `WarrantManager`

- `InvalidArgumentException` — `Duplicate schema for schema key ...` / `Duplicate schema for model ...` (when the registry is first resolved from the container)
- `OutOfBoundsException` — `No Warrant schema registered for model [%s].`
- `OutOfBoundsException` — `No Warrant schema registered for schema key [%s].`

## Authorization failures → `WarrantAuthorizationException`

Thrown by [`authorize()`](/reference/checking-api/#static-helpers) when a check is
denied. It extends `Illuminate\Auth\Access\AuthorizationException`, so Laravel
renders it as HTTP **403** automatically.

```php
public function __construct(
    string $message = 'This action is unauthorized.',
    ?WarrantDenialContext $denial = null,
);

public readonly ?WarrantDenialContext $denial; // the diagnosed denial, or null for a generic denial
```

The `$denial` property carries a diagnosed **denial-context data object** (a plain
`final readonly` object under `Warrant\`, **not** an exception) describing why the
check failed:

- `WarrantGate` — the requested `array $abilities` (normalized, wildcards resolved)
  and `AbilityMatchMode $matchMode`.
- `WarrantDenialContext` — `$user`, `?Model $target`, `string $schema`,
  `array $context`, `WarrantGate $gate`, the responsible `WarrantRule $rule` (the
  matching `cannot`), and `array $deniedAbilities`.
- `WarrantUngrantedContext` — same fields **minus** `$rule`, with
  `array $ungrantedAbilities` in place of `deniedAbilities` (the whole gate under
  `ANY`; the missing subset under `ALL`).

See [Denial messages](/guides/denial-messages/) for attaching messages and the
schema fallback hooks.

## Middleware errors

See [Middleware API](/reference/middleware-api/#errors). Unauthenticated or
unauthorized requests throw
[`WarrantAuthorizationException`](#authorization-failures--warrantauthorizationexception)
(rendered as 403); misconfiguration throws `InvalidArgumentException`. The
`warrant:` gate and the reachability guards use distinct message strings — see
the Middleware API errors table.

## "No authenticated user" → depends on the entry point

The message `... requires an authenticated user or an explicit user instance.` is
thrown by two different exception classes depending on where you hit it:

- `InvalidArgumentException` — from the static check helpers
  (`userHasAbilities`, `getUserAbilities`, `authorize`, `getNoTargetAbilitiesBag`)
  and the reachability helpers (`abilityReachability`, `userCouldEverHave`,
  `userAlwaysHas`, `userNeverHas`, and the `getUser*Abilities` bags).
- `LogicException` — from the query scopes / instance helpers that need a user
  but weren't given one (`scopeUserHasAbility`, `scopeSelectUserAbilities`,
  `loadUserAbilities`).

## Trait errors → `LogicException`

- `Model [%s] must return a WarrantSchema class string, got [%s].`
- `Schema [%s] must manage model [%s], got [%s].`

## Writer errors → `LogicException`

Thrown by `toSyntax()` when a rule can't be rendered as inline DSL — use
`toBoundSyntax()` instead:

- `A constant boolean expression has no rule-language representation.`
- `Condition parameter of type %s cannot be written inline; use toBoundSyntax().`
- `NAN/INF cannot be written inline; use toBoundSyntax().`
- `Float %s requires exponent notation, unsupported inline; use toBoundSyntax().`

## Configuration & driver errors → `RuntimeException`

- `No Warrant rule resolver configured. Set warrant.rule_resolver to a class implementing Warrant\RuleResolver.`
- `Warrant ability selection does not support the [%s] database driver.` (a driver other than PostgreSQL, MySQL/MariaDB, or SQLite for the per-row abilities column)
