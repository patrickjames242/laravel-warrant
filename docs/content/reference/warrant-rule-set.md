---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: WarrantSyntax and RuleSetNode
description: Parsing, validating, constructing, and rendering rules.
sidebar:
  order: 6
---

Every syntax node lives in `Warrant\DSL\Parsing\ASTNodes`: `WarrantSyntax`,
`RuleSetNode`, `SchemaConditionNode`, `AbilityBlockNode`, `RuleNode`,
`IncludeInvocationNode`, `CanClauseNode`, `CannotClauseNode`, the interfaces
`IRuleEntryNode` and `ISchemaScopedNode`, the expression nodes, and the leaves
every name and literal is held in: `NameNode` (`$name`) and `LiteralNode`
(`$value`). They are plain data and never touch the schema registry, so a rule set
takes a plain schema **key** string.

Every node knows where it came from. Its `$location` holds the source it was read
from (the text, its bindings, and what handed it to Warrant: a schema's
`rules()`, the rule provider, a template, a derived condition) and the span of
text it was written at. A node built in PHP has no span. Errors use the location
to point into the exact text a rule was written in, even after rules from many
sources have been joined.

## `WarrantSyntax` (readonly)

The root of every parse. Its `children` say what the source held, so one call
reads every form of rule text and nothing about the text has to be known first.
`Warrant::parse()` and `Warrant::parseFile()` are the same calls from the facade.

```php
public array $children;   // list<INode>

public static function parse(string|BoundSyntax $source): self;   // BoundSyntax: text with its bindings, as warrant() makes
public static function parseFile(string $path, array $bindings = []): self;

public function validate(): ValidSyntax;               // against the registered schemas
public function validateWithoutSchemas(): ValidSyntax; // against the rules of the language alone

public function isEmpty(): bool;
public function isExpression(): bool;
public function isSingleRule(): bool;
public function isRuleEntries(): bool;
public function isSchemaScoped(): bool;
public function isSingleRuleSet(): bool;
public function isRuleSets(): bool;
public function isSchemaCondition(): bool;
public function schemaKeys(): array;    // list<string>, distinct, source order
```

## `ValidSyntax` (final, readonly)

A `WarrantSyntax` that passed validation. Only a validated syntax hands out its
nodes or writes itself back as text:

```php
public function conditionExpression(): IBooleanExpressionNode;
public function rule(): RuleNode;
public function ruleEntries(): array;   // list<IRuleEntryNode>
public function ruleSet(): RuleSetNode;
public function ruleSets(): array;      // list<RuleSetNode>, source order, unmerged
public function scoped(): array;        // list<ISchemaScopedNode>
public function forSchema(string $schemaKey): ?RuleSetNode;   // every block for that schema, folded
public function scopedTo(string $schemaKey): RuleSetNode;

public function toSyntax(): string;
public function toBoundSyntax(): BoundSyntax;
```

Bindings stay in the tree as `BindingNode` placeholders; `value()` on one looks
its value up in the source it was written in. The values are put in place when a
guard expands the rules.

### What a parse returns

| Source | `children` | Accessor |
| --- | --- | --- |
| empty, or comments only | none | `isEmpty()` |
| `is_owner or is_admin` | one `IBooleanExpressionNode` | `conditionExpression()` |
| `if is_mine they can view` | one `RuleNode` | `rule()` |
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
how text with no `for` header is given its schema. Unscoped entries, or an empty
source, are placed in a `RuleSetNode` for `$schemaKey`. A single `for <schema>`
rule set is returned as it is, after checking its header names the same schema;
a mismatch throws `InvalidArgumentException`. Any other shape throws
`LogicException`.

```php
WarrantSyntax::parse('if is_mine they can view')->validate()->scopedTo('documents');       // RuleSetNode
WarrantSyntax::parse('for documents { they can view }')->validate()->scopedTo('documents'); // the same set
WarrantSyntax::parse('for folders { they can view }')->validate()->scopedTo('documents');   // throws
```

## `RuleSetNode` (final, readonly)

The rules for one schema: the body of a `for <schema>` header, or unscoped rules
given their schema by `scopedTo()`.

```php
public NameNode $schemaKey;
public array    $entries;   // list<IRuleEntryNode>: rules, ability blocks, includes, in source order

public function schemaKey(): string;
```

### Constructing

```php
public static function build(
    string $schemaKey,
    Closure $callback,   // ($rule) => { $rule()->...; }
): self;
```

From text, use `WarrantSyntax::parse($text)->validate()->ruleSet()` when the text
carries its own `for` header, or `->scopedTo($schemaKey)` when it does not. To
hand over several sources at once, return them as a list from `rules()`; Warrant
joins them.

### Reading

```php
public array $entries;   // list<IRuleEntryNode>: rules, ability blocks and includes, as written
```

An ability block stays in `$entries` as an `AbilityBlockNode` (`$abilities` and
`$entries`). Its body is generic, as the source writes it: the can/cannot clauses and
includes inside name no abilities, and the header is the only place they are
said. [Expansion](/sql/rule-to-query/#before-compiling-expansion) applies the
header to each entry, so the rules it produces grant and deny exactly what the
block does, and writing the tree back renders the block as a block. Every rule
and include held directly in `$entries` must name its own abilities; a generic
one there throws `InvalidArgumentException`.

The expanded rules are on the guard, as an `ExpandedSyntax` whose `$rules` is a
`list<ExpandedRule>`, and whose `ruleNodes()` gives the `RuleNode` each one reads,
to wrap in a `WarrantSyntax` and write back out:

```php
Warrant::forSchema(Document::class, $user)->expandedRuleSet()->rules;
Warrant::forSchema(Document::class, $user)->expandedRuleSet()->ruleNodes();
```

### Validating

```php
$syntax->validate();   // a ValidSyntax
```

`validate()` checks a parsed text as written: each `for` body against the schema
registered for the key its header names. Rules with no header are held only to
the rules of the language, since nothing says which schema they are for; a guard
checks their names when its schema's `rules()` or the rule provider hands them
over. To check stored text with no header against a schema now, give it the
header (error lines then count it):

```php
Warrant::parse("for {$schemaKey} {\n{$text}\n}")->validate();
```

It throws the first mistake, saying where in the text it is.

## `RuleNode` (readonly)

```php
public ?IBooleanExpressionNode $conditions;   // null means unconditional
public array $canClauses;                     // list<CanClauseNode>, one per `they can`
public array $cannotClauses;                  // list<CannotClauseNode>, each with its own message

public static function build(): WarrantRuleBuilder;

public function hasCannot(): bool;
public function isGeneric(): bool;                      // no can/cannot clause names an ability
public function withAbilities(array $abilities): self;   // a generic rule with $abilities on every can/cannot clause
```

`WarrantSyntax::parse($text)->validate()->rule()` throws if the text holds anything other
than exactly one rule. A rule carries no schema; the `RuleSetNode` holding it
does.

A can/cannot clause's `$abilities` is a `list<NameNode>`, and its `$message` the node it was
written as. Which abilities a rule grants and denies, and with what message, is read
off it once it is expanded, through [`ExpandedRule`](#expandedrule-readonly). A denial message lives on a `cannot` **clause**, not on the rule, so one rule can
deny two sets of abilities for two reasons. Give a cannot clause its message with
`because` in rule text, or with `theyCannotBecause()` on the builder.

Inside an ability block or a rule template's body a rule is generic: each can/cannot clause
has an empty `$abilities` list, because the block header or the `@include` names
them. `withAbilities()` gives such a rule its abilities, and throws on a rule that
already names its own.

## `ExpandedRule` (readonly)

One rule of an expanded rule set, as `$guard->expandedRuleSet()->rules` holds them.
Expansion has replaced every binding and `*`, so each clause names its abilities
one by one.

```php
public RuleNode $node;

public function grantedAbilities(): array;   // list<string>, the names every can clause lists
public function deniedAbilities(): array;    // list<string>
public function denies(string $ability): bool;
public function messageFor(string $ability): string|Closure|null;   // the first cannot clause listing it wins
```

## `IRuleProvider`

```php
interface IRuleProvider
{
    /** Any of: a rule set, a rule entry, a rule builder, rule text, or an iterable of those. */
    public function rules(RuleProviderContext $context): RuleSetNode|IRuleEntryNode|WarrantRuleBuilder|WarrantSyntax|BoundSyntax|string|iterable;
}
```

```php
final readonly class RuleProviderContext
{
    public string $schemaKey;
    public string $schema;
    public ?Authenticatable $user;
    public ?string $model;
}
```

## Round-tripping

`ValidSyntax::toSyntax()` and `toBoundSyntax()` render a validated tree back to
the language, and it parses back to an equal tree. They are the one place a tree
is written as text, so to write a single rule set, put it in a tree of its own:

```php
(new WarrantSyntax([$ruleSet]))->validate()->toSyntax();
```

A binding is written as its value: inline as a literal, and bound as a named
binding of the writer's own, `:b0`, `:b1` and so on, with the value collected
under that name.

The text is laid out by the [formatter](/editors/formatting/), so it is in the
house style. A `for <schema>` body is written as a braced `for <schema> { ... }`
block, which stays on one line when it holds one rule and fits.

`toSyntax()` can only render parameters expressible as inline literals. An array,
an object, `NAN`, `INF`, or a float needing exponent notation throws a
`LogicException`. `toBoundSyntax()` extracts every parameter as a named binding
and always works.

A closure denial message has no inline form, so `toSyntax()` throws on one and
`toBoundSyntax()` carries it losslessly as a named binding.

`@context`, `@column`, and `@sql` render as themselves in both forms, never as
bindings. A built `can` or `check` renders as
`can(<ability> for <schema>(<row>) with <k> = <v>)`.

An [ability block](/rules/ability-blocks/) or a [when block](/rules/when-blocks/)
stays a block in the tree, so it writes back as a block. An [`@include`](/rules/templates/) renders as itself
rather than as what it expands to.
