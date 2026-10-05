---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Exceptions
description: The exception classes and the denial-context data objects.
sidebar:
  order: 9
---

The full catalogue of messages, with the usual fix for each, is in
[the error reference](/diagnosis/errors/). This page is the classes.

## `WarrantSyntaxException`

```php
namespace Warrant\DSL\Parsing;

class WarrantSyntaxException extends RuntimeException
{
    public readonly string $source;
    public readonly int $offset;
    public readonly int $sourceLine;
    public readonly int $sourceColumn;
}
```

Thrown eagerly from the lexer and parser. The message includes the line, the
column, and a caret.

## `WarrantAuthorizationException`

```php
namespace Warrant;

class WarrantAuthorizationException extends \Illuminate\Auth\Access\AuthorizationException
{
    public function __construct(
        string $message = 'This action is unauthorized.',
        ?WarrantDenialContext $denial = null,
    );

    public readonly ?WarrantDenialContext $denial;
}
```

Thrown by `authorize()` and `authorizeAny()`. Because it extends Laravel's
`AuthorizationException`, the framework renders it as a 403 with no wiring.

`$denial` carries the diagnosed context, or null for a generic denial.

## Denial-context data objects

Plain `final readonly` objects under `Warrant\`, not exceptions.

### `WarrantGate`

```php
public readonly array $abilities;            // normalized, wildcards resolved
public readonly AbilityMatchMode $matchMode;
```

### `WarrantDenialContext`

```php
public readonly Authenticatable $user;
public readonly ?Model $target;              // null for a no-row check
public readonly string $schema;
public readonly array $context;              // the effective check-time context
public readonly WarrantGate $gate;
public readonly WarrantRuleNode $rule;       // the matching `cannot`
public readonly array $deniedAbilities;      // with `*` already resolved
```

### `WarrantUngrantedContext`

The same fields minus `$rule`, with `array $ungrantedAbilities` in place of
`deniedAbilities`. Under `ANY` that is the whole gate; under `ALL` it is the
missing subset.

## Which class for which failure

| Failure | Class |
|---|---|
| malformed rule syntax, or a binding mistake | `WarrantSyntaxException` |
| an unknown ability or condition name, a bad handle, wrong arity | `InvalidArgumentException` |
| a missing required context key | `InvalidArgumentException` |
| a condition emitting the wrong shape, or nothing | `InvalidArgumentException` |
| a condition that does not exist on the schema, at dispatch | `BadMethodCallException` |
| a schema and model that do not name each other | `LogicException` |
| a schema registered under two keys | `InvalidArgumentException` |
| an unresolvable schema reference | `OutOfBoundsException` |
| a rule that cannot be rendered inline | `LogicException` |
| a parse accessor that does not match the source's shape | `LogicException` |
| a `for` header that disagrees with `scopedTo()` | `InvalidArgumentException` |
| an unreadable rule file | `InvalidArgumentException` |
| a builder rule with no clause | `LogicException` |
| no resolver configured, an unsupported driver | `RuntimeException` |
| an authorization denial | `WarrantAuthorizationException` |
| no user available, from the engine | `InvalidArgumentException` |
| no user available, from a query scope | `LogicException` |

## Catching them

Validation, where both are worth surfacing to the author:

```php
try {
    Warrant::validate(Warrant::parse($text)->scopedTo($schemaKey));
} catch (WarrantSyntaxException $e) {
    // syntax: carries line, column, and the source
} catch (InvalidArgumentException $e) {
    // names: an unknown ability or condition
}
```

Authorization, where the denial context is available:

```php
try {
    Warrant::authorize('update', $document);
} catch (WarrantAuthorizationException $e) {
    $e->getMessage();
    $e->denial?->rule;
    $e->denial?->deniedAbilities;
}
```

A closure denial message may return a `Throwable` to throw as-is, which opts out of
the automatic 403 and uses that exception's own rendering. See
[denial messages](/rules/denial-messages/).
