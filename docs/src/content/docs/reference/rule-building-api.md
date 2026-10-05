---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Rule-building API
description: WarrantRuleSet, WarrantRule, the fluent builder, and the parser.
sidebar:
  order: 3
---

Reference for constructing rules. Conceptual coverage is in
[Providing rules](/guides/resolvers/) and [The rule language](/guides/rule-language/).

The `$schema` parameter throughout is a `Model` instance, a `WarrantSchema`
instance, a schema/model class-string, or a plain schema-key string.

## `Warrant` facade — the authoring front door

Four entry points, one per construct. Each parses Warrant syntax and takes exactly
the parameters of the constructor it delegates to.

```php
use Warrant\Facades\Warrant;

Warrant::condition(?string $syntax = null, array $bindings = []): IBooleanExpressionNode|WarrantConditionBuilder;
Warrant::rule(?string $syntax = null, Model|WarrantSchema|string|null $schema = null, array $bindings = []): WarrantRule|WarrantRuleBuilder;
Warrant::ruleSet(string $syntax, Model|WarrantSchema|string|null $schema = null, array $bindings = []): WarrantRuleSet;
Warrant::group(string $syntax, array $bindings = []): RuleSetGroup;
```

Given syntax, each returns the finished construct. Given nothing, `condition()` and
`rule()` return the builder that composes one — they are the two constructs that
*are* their fluent chain, so an empty call is meaningful. A rule set and a group are
collections, so their syntax is required; build those from values you already hold
with `WarrantRuleSet::fromRules()` or `RuleSetGroup::fromRuleSets()` below.

```php
Warrant::condition('is_owner or is_admin');                  // IBooleanExpressionNode
Warrant::condition()->if('is_owner')->orIf('is_admin');      // WarrantConditionBuilder
Warrant::rule('for documents if is_self they can view');     // WarrantRule
Warrant::ruleSet('for documents { they can view }');         // WarrantRuleSet
Warrant::group('for documents { … } for timesheets { … }');  // RuleSetGroup
```

Prefer naming the schema in the string's own `for` header rather than in the
`$schema` argument. The header travels with the string, so editor tooling reading
your source can tell which schema to check the names against; a string with no
header is simply left unchecked. A header and a `$schema` argument that disagree
are an error.

The header is accepted on a condition expression too, and discarded — an expression
has no schema field to carry it, and it exists purely so a condition written as a
string is as checkable as every other construct:

```php
Warrant::condition('for documents is_owner or is_admin');    // header parsed, then dropped
```

## `WarrantRuleSet` (readonly)

```php
public string $schemaKey;
public array  $rules;

public function __construct(Model|WarrantSchema|string $schema, array $rules);

public static function fromSyntax(
    string $syntax,
    Model|WarrantSchema|string|null $schema = null,
    array $bindings = [],
): self;

public static function fromRules(
    Model|WarrantSchema|string $schema,
    WarrantRule|WarrantRuleBuilder|array ...$rules,
): self; // flattens arrays; calls toRule() on builders; takes no bindings

public static function build(
    Model|WarrantSchema|string $schema,
    Closure $callback,          // ($rule) => { $rule()->...; }  — each call appends a rule
): self;

public function toSyntax(): string;          // canonical DSL, inline literals
public function toBoundSyntax(): BoundSyntax; // DSL + a positional bindings array
public function validate(): void;            // name-check against the registered schema
public static function validateAll(WarrantRuleSet|array ...$ruleSets): void;
```

`validate()` / `validateAll()` throw on the first unknown ability, condition, or
context-key name — useful for CI-checking [stored rules](/guides/testing/#validate-stored-rules-in-ci).
They also reject a rule that carries a [denial message](/guides/denial-messages/)
but has no `they cannot` clause (`InvalidArgumentException`).

## `WarrantRule` (readonly)

```php
public ?IBooleanExpressionNode $conditions; // null = unconditional
public ?string $schemaKey;                  // null = schema-less
public array $canAbilities;
public array $cannotClauses;                // list<CannotClause>; each carries its own message

public static function fromSyntax(string $syntax, Model|WarrantSchema|string|null $schema = null, array $bindings = []): self; // exactly one rule
public static function build(): WarrantRuleBuilder;

public function cannotAbilities(): array;              // every denied ability, flattened
public function messageFor(string $ability): string|Closure|null;
public function withDenialMessage(string|Closure $message, ?array $abilities = null): self; // a copy carrying the message
public function withSchemaKey(?string $schemaKey): self;
public function toSyntax(): string;
public function toBoundSyntax(): BoundSyntax;
```

`fromSyntax` throws if the string parses to zero or more than one rule.

A [denial message](/guides/denial-messages/) lives on a `cannot` *clause*, not on
the rule, so one rule can deny two sets of abilities for two different reasons;
`messageFor()` resolves the message for a given ability. `withDenialMessage()`
returns a new `WarrantRule` (the class is immutable), attaching the message to the
named abilities, or to every denied ability when `$abilities` is null. A message is
**not** representable in the string DSL, so `toSyntax()` / `toBoundSyntax()` drop it.

## `WarrantRuleBuilder`

Returned by `WarrantRule::build()`. Extends the condition builder with clause
methods.

### Condition methods (from `WarrantConditionBuilder`)

Each returns `static` and takes a condition name + parameters, **or** a closure (a
parenthesized group):

```php
->if(string|Closure $condition, array $parameters = [])
->andIf(...)     // alias of if; both mean `and`
->orIf(...)      // `or`
->ifNot(...)     // `and not`
->andIfNot(...)  // `and not`
->orIfNot(...)   // `or not`

->ifRaw(string $expression, array $bindings = [])   // splice a parsed DSL fragment as one group
->orIfRaw(string $expression, array $bindings = [])

->when(mixed $condition, Closure $callback): static // Laravel-style conditional
```

### Cross-schema methods (from `WarrantConditionBuilder`)

```php
->ifCan(string $ability, Model|WarrantSchema|string $schema, mixed $key = new NoRow, array $with = [])
->andIfCan(...)   // alias of ifCan
->orIfCan(...)    // `or can(...)`

->ifCheck(string|Closure $predicate, Model|WarrantSchema|string $schema, mixed $key = new NoRow, array $with = [])
->andIfCheck(...) // alias of ifCheck
->orIfCheck(...)  // `or check(...)`
```

### `NoRow` and `Ref`

```php
new Warrant\Builders\NoRow                        // the default $key: an unbound handle
Warrant\Builders\Ref::context(string $key): ContextRef            // @context <key>
Warrant\Builders\Ref::column(string $column): ColumnRef                     // @column <column>
Warrant\Builders\Ref::column(string $frame, string $column): ColumnRef      // @column <name>.<column>
Warrant\Builders\Ref::sql(string $sql): SqlRef                    // @sql "<sql>"
```

A `Ref` is valid anywhere the builder takes an argument value: a condition
parameter, a cross-schema row selector, or a `with` map value.

### Clause methods (from `WarrantRuleBuilder`)

```php
->theyCan(string ...$abilities): static     // additive
->theyCannot(string ...$abilities): static  // additive
->theyCannotBecause(string|list<string> $abilities, string|Closure $message): static // deny with a message
->toRule(): WarrantRule                      // throws LogicException if no clause set
```

`theyCannotBecause()` adds one clause per call, so separate calls give separate
abilities separate messages; abilities passed together share one message. To
attach a message to an existing rule instead, use
[`WarrantRule::withDenialMessage()`](#warrantrule-readonly). See
[Denial messages](/guides/denial-messages/) for what a message closure receives
and where the message surfaces.

### Semantics

- Precedence is `not` > `and` > `or`, identical to the DSL — the builder produces
  a byte-for-byte identical AST.
- A **closure is a parenthesized group** and receives a bare
  `WarrantConditionBuilder` (no `theyCan`/`theyCannot`).
- An **empty group folds to `false`** — nothing in an `or`, a veto in an `and`.
- Condition parameters may be **any PHP value** — nothing is stringified.
- `can` and `check` have **no negated variants** — negate one with a group,
  `->ifNot(fn ($c) => $c->ifCan(...))`.
- Omitting `$key` gives an **unbound handle**; an explicit `key: null` stays
  row-bound and is rejected by `validate()`, so a missing id fails loudly instead
  of widening a row question into a schema-wide one.
- An **empty `check` predicate closure throws `LogicException`** — unlike a group
  it cannot fall back to `false`, because a predicate may not contain a constant.
- `$schema` is normalized to a schema key through the registry, so a model or
  schema class-string that resolves to nothing throws `OutOfBoundsException` at
  build time. A plain unregistered *key* string passes through, and a typo'd key is
  caught by `validate()`.

## `WarrantParser` (final)

```php
public static function parse(string $source, array $bindings = []): array;              // WarrantRule[]
public static function parseSingleRule(string $source, array $bindings = []): WarrantRule;
public static function parseConditionExpression(string $source, array $bindings = []): IBooleanExpressionNode;
```

## Round-tripping

`toSyntax()` and `toBoundSyntax()` render a rule back to the DSL and parse-back
identically. `toSyntax()` can only render parameters that are expressible as
**inline literals** (scalars); a parameter that's an array, object, `NAN`, `INF`,
or a float needing exponent notation throws a `LogicException` — use
`toBoundSyntax()`, which extracts every parameter as a positional binding.
`@context` references render as `@context <key>` in both forms and never consume a
positional binding, and so do `@column` and `@sql`. A built `can`/`check` renders
as `can(<ability> for <schema>(<row>) with <k> = <v>)`, so a builder-authored
cross-schema rule round-trips like any other.
