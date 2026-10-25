---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Building rule sets
description: The ways to construct one, and when each reads best.
sidebar:
  order: 2
---

A rule set is a `RuleSetNode` for one schema key. Text becomes one through
`WarrantSyntax::parse()`, and PHP builds one with `Build::ruleSet()`. Every
node lives in `Warrant\DSL\Parsing\ASTNodes`.

A parsed tree hands out its nodes once it is validated: `validate()` checks every
name against the schemas the application registers and answers a `ValidSyntax`,
whose accessors are below.

## From text

```php
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;

WarrantSyntax::parse('for documents { if is_mine they can view }')->validate()->ruleSet();
WarrantSyntax::parse(warrant('for documents { if in_team(:t) they can view }', ['t' => 'sales']))->validate()->ruleSet();
```

`Warrant::parse()` is the same call from the facade. One parse reads every form of
rule text; the `WarrantSyntax` it returns says what the text held, and `ruleSet()`
asks for the single `for <schema>` rule set.

Text with no header is given its schema by `scopedTo()`:

```php
WarrantSyntax::parse('if is_mine they can view')->validate()->scopedTo('documents');
```

Prefer the header. It travels with the string, so `validate()` and editor
tooling both know which schema to check the condition and ability names against.
Text with no header is checked by `validate()` only against the rules of the
language, and its names when a guard first uses it. A header that disagrees with
`scopedTo()` is an error.

## Several rules at once

There is no set to build around rules you already have. `rules()` takes a list,
in any mix of text, rules, builders and rule sets, and joins them:

```php
return [
    'if is_mine they can view, update',
    Build::rule()->theyCannot('delete'),
];
```

An empty list, or empty text, is no rules at all, which is the right answer for a
user with no access:

```php
return [];
```

## With a build callback

Each `$rule()` call starts a fresh rule and appends it. You never call `toRule()`
yourself:

```php
Build::ruleSet('documents', function ($rule) {
    $rule()->if('is_mine')->theyCan('view', 'update');
    $rule()->if('is_locked')->theyCannotBecause('update', 'This document is locked.');
    $rule()->theyCannot('delete');
});
```

This is usually the most readable shape to return from a provider when the policy
is code rather than data. The full builder surface is in
[the PHP builder](/rules/builder/).

## Straight from the parser

When you want the parsed rules without a set around them, ask the tree for the
shape the text holds:

```php
$entries = WarrantSyntax::parse('if is_mine they can view')->validate()->ruleEntries();   // IRuleEntryNode[]
$one     = WarrantSyntax::parse('they cannot delete')->validate()->rule();               // RuleNode
$expr    = WarrantSyntax::parse('a or not b')->validate()->conditionExpression();        // an expression
```

An accessor that does not match the text throws a `LogicException` naming what
the text holds:

```text
Expected a single rule, but the source holds a rule set for [documents].
```

## Several schemas at once

One source can hold a braced block per schema:

```php
$syntax = Warrant::parse(<<<'WARRANT'
    for documents {
        if is_mine they can view, update
    }

    for folders {
        if is_owner they can view
    }
WARRANT)->validate();

$syntax->forSchema('documents');   // the RuleSetNode, or null
$syntax->schemaKeys();             // ['documents', 'folders']
$syntax->ruleSets();               // every block, in source order
```

`forSchema()` folds every block targeting the same schema into one set, entries
concatenated in source order. `ruleSets()` returns the blocks as written,
unmerged. With more than one block, every block must be braced:

```text
Multiple rule sets in one source must each be braced, as `for <schema> { ... }`.
```

`WarrantSyntax::parseFile()` and `Warrant::parseFile()` read the same thing from
a file.

## Round-tripping

A validated tree renders back to the language:

```php
$syntax->toSyntax();        // canonical text, inline literals
$syntax->toBoundSyntax();   // text plus its bindings, by name
```

To render one rule set, put it in a tree of its own:

```php
(new WarrantSyntax([$set]))->validate()->toSyntax();
```

`toSyntax()` can only render parameters expressible as inline literals. An array,
an object, `NAN`, `INF`, or a float needing exponent notation throws a
`LogicException`, and `toBoundSyntax()` is the answer, since it extracts every
parameter as a named binding of its own: `:b0`, `:b1` and so on, in the order
written.

```text
Condition parameter of type array cannot be written inline; use toBoundSyntax().
```

A closure denial message has no inline form either, so `toSyntax()` throws on one
and `toBoundSyntax()` carries it losslessly.

`@context`, `@column`, and `@sql` render as themselves in both forms, never as
bindings.
