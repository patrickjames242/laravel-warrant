---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: WarrantSyntax and RuleSetNode
description: Parsing, constructing, merging, validating, and rendering rules.
sidebar:
  order: 6
---

Every syntax node lives in `Warrant\DSL\Parsing\ASTNodes`: `WarrantSyntax`,
`RuleSetNode`, `SchemaConditionNode`, `AbilityBlockNode`, `WarrantRuleNode`,
`IncludeInvocationNode`, `CanClauseNode`, `CannotClauseNode`, the interfaces
`IRuleEntryNode` and `ISchemaScopedNode`, and the expression nodes. They are plain
data and never touch the schema registry, so a rule set takes a plain schema
**key** string.

## `WarrantSyntax` (final, readonly)

The root of every parse. Its `children` say what the source held, so one call
reads every form of rule text and nothing about the text has to be known first.
`Warrant::parse()` and `Warrant::parseFile()` are the same calls from the facade.

```php
public array $children;   // list<INode>

public static function parse(string $source, array $bindings = []): self;
public static function parseFile(string $path, array $bindings = []): self;

public function isEmpty(): bool;
public function isExpression(): bool;
public function isSingleRule(): bool;
public function isRuleEntries(): bool;
public function isSchemaScoped(): bool;
public function isSingleRuleSet(): bool;
public function isRuleSets(): bool;
public function isSchemaCondition(): bool;

public function conditionExpression(): IBooleanExpressionNode;
public function rule(): WarrantRuleNode;
public function ruleEntries(): array;   // list<IRuleEntryNode>
public function ruleSet(): RuleSetNode;
public function ruleSets(): array;      // list<RuleSetNode>, source order, unmerged
public function scoped(): array;        // list<ISchemaScopedNode>
public function schemaKeys(): array;    // list<string>, distinct, source order
public function forSchema(string $schemaKey): ?RuleSetNode;   // every block for that schema, folded
public function scopedTo(string $schemaKey): RuleSetNode;

public function toSyntax(): string;
public function toBoundSyntax(): BoundSyntax;
```

### What a parse returns

| Source | `children` | Accessor |
| --- | --- | --- |
| empty, or comments only | none | `isEmpty()` |
| `is_owner or is_admin` | one `IBooleanExpressionNode` | `conditionExpression()` |
| `if is_mine they can view` | one `WarrantRuleNode` | `rule()` |
| several rules, `can they … { … }` blocks or `@include`s, no header | `IRuleEntryNode`, … | `ruleEntries()` |
| `for documents` then a body, braced or not | one `RuleSetNode` | `ruleSet()` |
| `for documents { … } for folders { … }` | `RuleSetNode`, … | `ruleSets()`, `forSchema()`, `schemaKeys()` |
| `for documents is_owner or is_admin` | one `SchemaConditionNode` | `conditionExpression()` |
| braced blocks of rules and of conditions | `ISchemaScopedNode`, … | `scoped()` |

An accessor that does not match the shape throws a `LogicException` naming what
the source holds:

```text
Expected a single rule, but the source holds a rule set for [documents].
```

An empty source answers an empty list from `ruleEntries()`, `ruleSets()` and
`scoped()`.

The shapes never mix, and the parser rejects a source that tries:

```text
Multiple rule sets in one source must each be braced, as `for <schema> { ... }`.
A `{ ... }` block needs a `for <schema>` header before it.
Rules without a `for` header cannot be followed by a `for` block; put them in a block of their own.
```

A condition expression written beside rules is rejected too.

`parseFile()` throws on an unreadable path:

```text
Unable to read Warrant rule file [/app/warrant/editor.warrant].
```

### `scopedTo()`

`scopedTo($schemaKey)` turns a source into one rule set for that schema, and is
how text with no `for` header is given its schema. Headless entries, or an empty
source, are placed in a `RuleSetNode` for `$schemaKey`. A single `for <schema>`
rule set is returned as it is, after checking its header names the same schema;
a mismatch throws `InvalidArgumentException`. Any other shape throws
`LogicException`.

```php
WarrantSyntax::parse('if is_mine they can view')->scopedTo('documents');       // RuleSetNode
WarrantSyntax::parse('for documents { they can view }')->scopedTo('documents'); // the same set
WarrantSyntax::parse('for folders { they can view }')->scopedTo('documents');   // throws
```

## `RuleSetNode` (final, readonly)

The rules for one schema: the body of a `for <schema>` header, or headless rules
given their schema by `scopedTo()`.

```php
public string $schemaKey;
public array  $entries;   // list<IRuleEntryNode>: rules, ability blocks, includes, in source order

public function __construct(string $schemaKey, array $entries = []);
```

### Constructing

```php
public static function fromRules(
    string $schemaKey,
    WarrantRuleNode|WarrantRuleBuilder|array ...$rules,
): self;   // flattens arrays, calls toRule() on builders, takes no bindings

public static function build(
    string $schemaKey,
    Closure $callback,   // ($rule) => { $rule()->...; }
): self;
```

From text, use `WarrantSyntax::parse($text)->ruleSet()` when the text carries its
own `for` header, or `->scopedTo($schemaKey)` when it does not.

### Reading

```php
public array $entries;   // list<IRuleEntryNode>: rules, ability blocks and includes, as written
```

An ability block stays in `$entries` as an `AbilityBlockNode` (`$abilities` and
`$entries`). Its body is headless, as the source writes it: the clauses and
includes inside name no abilities, and the header is the only place they are
said. [Expansion](/sql/rule-to-query/#before-compiling-expansion) applies the
header to each entry, so the rules it produces grant and deny exactly what the
block does, and writing the tree back renders the block as a block. Every rule
and include held directly in `$entries` must name its own abilities; a headless
one there throws `InvalidArgumentException`.

The expanded rules are on the guard, as an `ExpandedRuleSet` whose `$rules` is a
`list<WarrantRuleNode>`:

```php
Warrant::forSchema(Document::class, $user)->expandedRuleSet()->rules;
```

### Merging

```php
public function mergeWith(RuleSetNode $other): self;
public static function merge(RuleSetNode $first, RuleSetNode ...$rest): self;
```

Both concatenate entries, this set's first, into a new set. The schema keys must
match:

```text
Cannot merge rule sets for different schemas: [documents] and [folders].
```

### Validating

```php
Warrant::validate(RuleSetNode|array ...$ruleSets): void;
```

`Warrant::validate()` checks each set against the schema registered for its own
key and throws on the first unknown ability or condition.

## `WarrantRuleNode` (readonly)

```php
public ?IBooleanExpressionNode $conditions;   // null means unconditional
public array $canClauses;                     // list<CanClauseNode>, one per `they can`
public array $cannotClauses;                  // list<CannotClauseNode>, each with its own message

public static function build(): WarrantRuleBuilder;

public function canAbilities(): array;
public function cannotAbilities(): array;
public function deniesAbility(string $ability): bool;
public function messageFor(string $ability): string|Closure|null;
public function hasCannot(): bool;
public function isHeadless(): bool;                      // no clause names an ability
public function withAbilities(array $abilities): self;   // a headless rule with $abilities on every clause
```

`WarrantSyntax::parse($text)->rule()` throws if the text holds anything other
than exactly one rule. A rule carries no schema; the `RuleSetNode` holding it
does.

A denial message lives on a `cannot` **clause**, not on the rule, so one rule can
deny two sets of abilities for two reasons. Give a clause its message with
`because` in rule text, or with `theyCannotBecause()` on the builder.

Inside an ability block or a rule template's body a rule is headless: each clause
has an empty `$abilities` list, because the block header or the `@include` names
them. `withAbilities()` gives such a rule its abilities, and throws on a rule that
already names its own.

## `WarrantParser` (final)

```php
public static function parse(string $source, array $bindings = []): WarrantSyntax;
```

The parser behind `WarrantSyntax::parse()`. Bindings are resolved as the tree is
built, so the nodes hold only concrete values.

## `RuleResolver`

```php
interface RuleResolver
{
    public function resolve(RuleResolutionContext $context): RuleSetNode;
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

`WarrantSyntax::toSyntax()` and `toBoundSyntax()` render a tree back to the
language, and it parses back to an equal tree. They are the one place a tree is
written as text, so to write a single rule set, put it in a tree of its own:

```php
(new WarrantSyntax([$ruleSet]))->toSyntax();
```

A `for <schema>` body is written as a `for <schema> { ... }` block, one blank line
from the next.

`toSyntax()` can only render parameters expressible as inline literals. An array,
an object, `NAN`, `INF`, or a float needing exponent notation throws a
`LogicException`. `toBoundSyntax()` extracts every parameter as a positional
binding and always works.

A closure denial message has no inline form, so `toSyntax()` throws on one and
`toBoundSyntax()` carries it losslessly as a `?` binding.

`@context`, `@column`, and `@sql` render as themselves in both forms and never
consume a positional binding. A built `can` or `check` renders as
`can(<ability> for <schema>(<row>) with <k> = <v>)`.

An [ability block](/rules/ability-blocks/) stays a block in the tree, so it
writes back as a block. An [`@include`](/rules/templates/) renders as itself
rather than as what it expands to.
