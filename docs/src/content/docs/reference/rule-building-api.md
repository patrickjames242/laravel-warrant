---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Rule-building API
description: WarrantSyntax, RuleSetNode, WarrantRuleNode, the fluent builder, and the parser.
sidebar:
  order: 3
---

Reference for constructing rules. Conceptual coverage is in
[Providing rules](/guides/providers/) and [The rule language](/guides/rule-language/).

Every syntax node lives in `Warrant\DSL\Parsing\ASTNodes`: `WarrantSyntax`,
`RuleSetNode`, `SchemaConditionNode`, `AbilityBlockNode`, `WarrantRuleNode`, `IncludeInvocationNode`,
`CannotClauseNode`, the interfaces `IRuleEntryNode` and `ISchemaScopedNode`, and the
expression nodes. A rule set takes a plain schema **key** string. The builder's
cross-schema `$schema` parameter is a `Model` instance, a `WarrantSchema`
instance, a schema/model class-string, or a plain schema-key string.

## `Warrant` facade — the authoring front door

```php
use Warrant\Facades\Warrant;

Warrant::parse(string $syntax, array $bindings = []): WarrantSyntax;
Warrant::parseFile(string $path, array $bindings = []): WarrantSyntax;
Warrant::validate(RuleSetNode|array ...$ruleSets): void;
Warrant::condition(): WarrantConditionBuilder;
Warrant::rule(): WarrantRuleBuilder;
Warrant::ruleTemplate(string $syntax, array $bindings = []): WarrantRuleTemplate;
```

There is one parse for every form of rule text. `parse()` reads the source and
returns a `WarrantSyntax` tree whose children say what the source held; you ask
the tree for the shape you expect. `parseFile()` does the same for a file, such as
a `.warrant` file; the extension may be left off, and a path naming no file is
read with `.warrant` appended. `condition()` and `rule()` take no arguments and
return the fluent builder for each.

```php
Warrant::parse('is_owner or is_admin')->conditionExpression();            // IBooleanExpressionNode
Warrant::parse('if is_self they can view')->rule();                        // WarrantRuleNode
Warrant::parse('if is_self they can view')->scopedTo('documents');         // RuleSetNode
Warrant::parse('for documents { they can view }')->ruleSet();              // RuleSetNode
Warrant::parse('for documents { … } for timesheets { … }')->forSchema('documents'); // ?RuleSetNode
Warrant::parseFile(resource_path('warrant/documents'))->ruleSet();         // reads documents.warrant
Warrant::condition()->if('is_owner')->orIf('is_admin');                    // WarrantConditionBuilder
```

## `WarrantSyntax` (final, readonly)

The root of every parse. `WarrantSyntax::parse()` and `WarrantSyntax::parseFile()`
are the same calls as the facade's.

```php
public array $children; // list<INode>

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
public function ruleEntries(): array; // list<IRuleEntryNode>
public function ruleSet(): RuleSetNode;
public function ruleSets(): array;    // list<RuleSetNode>, source order, unmerged
public function scoped(): array;      // list<ISchemaScopedNode>
public function schemaKeys(): array;  // list<string>, distinct, source order
public function forSchema(string $schemaKey): ?RuleSetNode; // every block for that schema, folded
public function scopedTo(string $schemaKey): RuleSetNode;

public function toSyntax(): string;          // the whole tree as rule text, inline literals
public function toBoundSyntax(): BoundSyntax; // the same, plus a positional bindings array
```

`WarrantSyntax` is the one place a tree is written back to rule text. To write a
single rule or rule set, put it in a tree of its own:
`(new WarrantSyntax([$ruleSet]))->toSyntax()`.

### What a parse returns

| Source | `children` | Accessor |
| --- | --- | --- |
| empty, or comments only | none | `isEmpty()` |
| `is_owner or is_admin` | one `IBooleanExpressionNode` | `conditionExpression()` |
| `if is_self they can view` | one `WarrantRuleNode` | `rule()` |
| several rules, `can they … { … }` blocks or `@include`s, no header | `IRuleEntryNode`, … | `ruleEntries()` |
| `for documents` then a body, no braces | one `RuleSetNode` | `ruleSet()` |
| `for documents { … }` | one `RuleSetNode` | `ruleSet()` |
| `for documents { … } for timesheets { … }` | `RuleSetNode`, … | `ruleSets()`, `forSchema()`, `schemaKeys()` |
| `for documents is_owner or is_admin` | one `SchemaConditionNode` | `conditionExpression()` |
| braced blocks of rules and of conditions | `ISchemaScopedNode`, … | `scoped()` |

An accessor that does not match the shape throws a `LogicException` naming what
the source holds — `Expected a single rule, but the source holds a rule set for
[documents].` An empty source answers an empty list from `ruleEntries()`,
`ruleSets()` and `scoped()`.

`conditionExpression()` answers the expression under a `for <schema>` header as well as a
bare one. The header names the schema whose conditions the expression uses, so
editor tooling can check the names, and it changes nothing about the tree.

The shapes never mix, and the parser rejects a source that tries:

- ``Multiple rule sets in one source must each be braced, as `for <schema> { ... }`.``
  — a bare `for` body runs to the end of the input, so a second rule set needs
  braces, and so does the first.
- ``A `{ ... }` block needs a `for <schema>` header before it.``
- ``Rules without a `for` header cannot be followed by a `for` block; put them in a block of their own.``
- A condition expression written beside rules.

### `scopedTo()`

`scopedTo($schemaKey)` turns a source into one rule set for that schema. Unscoped
entries — written with no `for <schema>` header — or an empty source, are placed in a `RuleSetNode` for `$schemaKey`. A single
`for <schema>` rule set is returned as it is, after checking that its header names
the same schema; a header that disagrees throws `InvalidArgumentException`. Any
other shape throws `LogicException`.

```php
WarrantSyntax::parse('if is_self they can view')->scopedTo('documents');          // RuleSetNode for documents
WarrantSyntax::parse('for documents { they can view }')->scopedTo('documents');   // the same rule set
WarrantSyntax::parse('for timesheets { they can view }')->scopedTo('documents');  // InvalidArgumentException
```

Prefer naming the schema in the text's own `for` header where you write the text.
The header travels with the string, so editor tooling reading your source can tell
which schema to check the names against; a string with no header is left
unchecked.

## `RuleSetNode` (final, readonly)

The rules for one schema: the body of a `for <schema>` header, or unscoped rules
given their schema by `scopedTo()`.

```php
public string $schemaKey;
public array  $entries;  // list<IRuleEntryNode>: rules, ability blocks, includes, in source order

public function __construct(string $schemaKey, array $entries = []);

public static function fromRules(
    string $schemaKey,
    WarrantRuleNode|WarrantRuleBuilder|array ...$rules,
): self; // flattens arrays; calls toRule() on builders; takes no bindings

public static function build(
    string $schemaKey,
    Closure $callback,          // ($rule) => { $rule()->...; }  — each call appends a rule
): self;

public static function merge(RuleSetNode $first, RuleSetNode ...$rest): self; // same schema, argument order
public function mergeWith(RuleSetNode $other): self;

public array $entries;                 // list<IRuleEntryNode>: rules, ability blocks and includes, as written
```

An ability block stays in `$entries` as an `AbilityBlockNode` (`$abilities` and
`$entries`). Its body is generic, as the source writes it: the clauses and
includes inside name no abilities, and the header is the only place they are
said. [Expansion](/guides/how-it-compiles/#before-compiling-expansion) applies the
header to each entry, so the rules it produces grant and deny exactly what the
block does, and writing the tree back renders the block as a block. Every rule
and include held directly in `$entries` must name its own abilities; a generic
one there throws `InvalidArgumentException`. Merging rule sets for two different schemas throws
`InvalidArgumentException`.

### Validating

```php
Warrant::validate($ruleSet);
Warrant::validate($documents, $timesheets);
Warrant::validate([$documents, $timesheets]);
```

`Warrant::validate()` checks each rule set against the schema registered for its
own key and throws on the first unknown ability, condition, or context-key name —
useful for CI-checking [stored rules](/guides/testing/#validate-stored-rules-in-ci).

## `WarrantRuleNode` (readonly)

```php
public ?IBooleanExpressionNode $conditions; // null = unconditional
public array $canClauses;                   // list<CanClauseNode>, one per `they can`
public array $cannotClauses;                // list<CannotClauseNode>; each carries its own message

public static function build(): WarrantRuleBuilder;

public function canAbilities(): array;                 // every granted ability, flattened
public function cannotAbilities(): array;              // every denied ability, flattened
public function messageFor(string $ability): string|Closure|null;
public function isGeneric(): bool;                    // no clause names an ability
public function withAbilities(array $abilities): self; // a generic rule with $abilities on every clause
```

Inside an ability block or a rule template's body a rule is generic: each
`they can` / `they cannot` clause has an empty `$abilities` list, because the
block header or the `@include` names them. `withAbilities()` gives such a rule the
abilities it takes, and throws on a rule that already names its own.

A rule carries no schema; the `RuleSetNode` that holds it does. Parse a single rule
with `WarrantSyntax::parse($text)->rule()`, which throws if the text holds
anything other than exactly one rule.

A [denial message](/guides/denial-messages/) lives on a `cannot` *clause*, not on
the rule, so one rule can deny two sets of abilities for two different reasons;
`messageFor()` resolves the message for a given ability. Give a clause its
message with `because` in rule text, or with `theyCannotBecause()` on the builder.
A string message writes back as a `because '…'` clause; a closure message has no
inline form, so `toSyntax()` throws on it and `toBoundSyntax()` carries it as a
binding.

## `WarrantRuleBuilder`

Returned by `WarrantRuleNode::build()`. Extends the condition builder with clause
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
->theyCan(string ...$abilities): static     // additive; one can clause per call
->theyCannot(string ...$abilities): static  // additive
->theyCannotBecause(string|list<string> $abilities, string|Closure $message): static // deny with a message
->toRule(): WarrantRuleNode                      // throws LogicException if no clause set
```

`theyCannotBecause()` adds one clause per call, so separate calls give separate
abilities separate messages; abilities passed together share one message. See
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
  row-bound and is rejected by `Warrant::validate()`, so a missing id fails loudly instead
  of widening a row question into a schema-wide one.
- An **empty `check` predicate closure throws `LogicException`** — unlike a group
  it cannot fall back to `false`, because a predicate may not contain a constant.
- `$schema` is normalized to a schema key through the registry, so a model or
  schema class-string that resolves to nothing throws `OutOfBoundsException` at
  build time. A plain unregistered *key* string passes through, and a typo'd key is
  caught by `Warrant::validate()`.

## `WarrantParser` (final)

```php
public static function parse(string $source, array $bindings = []): WarrantSyntax;
```

The parser behind `WarrantSyntax::parse()`. Bindings are resolved as the tree is
built, so the nodes hold only concrete values.

## Round-tripping

`WarrantSyntax::toSyntax()` and `toBoundSyntax()` render a tree back to the DSL,
and parsing the result gives back an equal tree. `toSyntax()` can only render parameters that are expressible as
**inline literals** (scalars); a parameter that's an array, object, `NAN`, `INF`,
or a float needing exponent notation throws a `LogicException` — use
`toBoundSyntax()`, which extracts every parameter as a positional binding.
`@context` references render as `@context <key>` in both forms and never consume a
positional binding, and so do `@column` and `@sql`. A built `can`/`check` renders
as `can(<ability> for <schema>(<row>) with <k> = <v>)`, so a builder-authored
cross-schema rule round-trips like any other.
