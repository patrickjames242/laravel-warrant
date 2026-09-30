---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: WarrantRuleSet and WarrantRule
description: Constructing, merging, validating, and rendering rules.
sidebar:
  order: 6
---

The `$schema` parameter throughout is a `Model` instance, a `WarrantSchema`
instance, a schema or model class-string, or a plain schema-key string.

## `WarrantRuleSet` (readonly)

```php
public string $schemaKey;
public array  $rules;

public function __construct(Model|WarrantSchema|string $schema, array $rules);
```

### Constructing

```php
public static function fromSyntax(
    string $syntax,
    Model|WarrantSchema|string|null $schema = null,
    array $bindings = [],
): self;

public static function fromRules(
    Model|WarrantSchema|string $schema,
    WarrantRule|WarrantRuleBuilder|array ...$rules,
): self;   // flattens arrays, calls toRule() on builders, takes no bindings

public static function build(
    Model|WarrantSchema|string $schema,
    Closure $callback,   // ($rule) => { $rule()->...; }
): self;
```

The schema may be given by a `for <schema>` header in the syntax and/or the
`$schema` argument. At least one is required, and if both are given they must
agree.

### Merging

```php
public function mergeWith(WarrantRuleSet $other): self;
public static function merge(WarrantRuleSet $first, WarrantRuleSet ...$rest): self;
```

Both concatenate rules, this set's first, into a new set. The schema keys must
match:

```text
Cannot merge rule sets for different schemas: [documents] and [folders].
```

### Validating and rendering

```php
public function validate(): void;
public static function validateAll(WarrantRuleSet|array ...$ruleSets): void;

public function toSyntax(): string;
public function toBoundSyntax(): BoundSyntax;
```

`validate()` throws on the first unknown ability or condition, and rejects a rule
carrying a denial message with no `cannot` clause.

## `WarrantRule` (readonly)

```php
public ?IBooleanExpressionNode $conditions;   // null means unconditional
public ?string $schemaKey;                    // null means schema-less
public array $canAbilities;
public array $cannotClauses;                  // list<CannotClause>, each with its own message

public static function fromSyntax(string $syntax, Model|WarrantSchema|string|null $schema = null, array $bindings = []): self;
public static function build(): WarrantRuleBuilder;

public function cannotAbilities(): array;
public function messageFor(string $ability): string|Closure|null;
public function withDenialMessage(string|Closure $message, ?array $abilities = null): self;
public function withSchemaKey(?string $schemaKey): self;
public function toSyntax(): string;
public function toBoundSyntax(): BoundSyntax;
```

`fromSyntax` throws if the string parses to zero or more than one rule.

A denial message lives on a `cannot` **clause**, not on the rule, so one rule can
deny two sets of abilities for two reasons. `withDenialMessage()` returns a copy,
since the class is immutable.

## `RuleSetGroup` (readonly)

An ordered collection, one merged set per distinct schema, authored together as
`for <schema> { ... }` blocks. Blocks targeting the same schema are merged in
source order.

```php
public array $ruleSets;

public static function fromSyntax(string $syntax, array $bindings = []): self;
public static function fromFile(string $path, array $bindings = []): self;
public static function fromRuleSets(WarrantRuleSet|array ...$ruleSets): self;

public function forSchema(Model|WarrantSchema|string $schema): ?WarrantRuleSet;
public function schemaKeys(): array;
public function toSyntax(): string;
public function toBoundSyntax(): BoundSyntax;
```

It is `IteratorAggregate` and `Countable`:

```php
foreach ($group as $ruleSet) { $ruleSet->validate(); }
count($group);
```

`fromFile()` throws on an unreadable path:

```text
Cannot read Warrant rule file [/app/warrant/editor.warrant].
```

## `WarrantParser` (final)

```php
public static function parse(string $source, array $bindings = []): array;              // WarrantRule[]
public static function parseSingleRule(string $source, array $bindings = []): WarrantRule;
public static function parseConditionExpression(string $source, array $bindings = []): IBooleanExpressionNode;
```

## `RuleResolver`

```php
interface RuleResolver
{
    public function resolve(RuleResolutionContext $context): WarrantRuleSet;
}
```

```php
final readonly class RuleResolutionContext
{
    public string $schemaKey;
    public string $schema;
    public ?Authenticatable $user;
    public ?string $model;
}
```

## Round-tripping

`toSyntax()` and `toBoundSyntax()` render back to the language and parse back
identically.

`toSyntax()` can only render parameters expressible as inline literals. An array,
an object, `NAN`, `INF`, or a float needing exponent notation throws a
`LogicException`. `toBoundSyntax()` extracts every parameter as a positional
binding and always works.

A closure denial message has no inline form, so `toSyntax()` throws on one and
`toBoundSyntax()` carries it losslessly as a `?` binding.

`@context`, `@column`, and `@sql` render as themselves in both forms and never
consume a positional binding. A built `can` or `check` renders as
`can(<ability> for <schema>(<row>) with <k> = <v>)`.

An [ability block](/rules/ability-blocks/) is expanded as it is parsed, so
`toSyntax()` renders the longhand rather than reconstructing the block. An
[`@include`](/rules/templates/) renders as itself rather than as what it expands
to.
