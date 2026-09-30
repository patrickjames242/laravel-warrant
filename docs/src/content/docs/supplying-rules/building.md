---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Building rule sets
description: Four ways to construct one, and when each reads best.
sidebar:
  order: 2
---

The schema argument throughout is a model instance, a schema instance, a
schema or model class string, or a plain schema-key string.

## From text

```php
WarrantRuleSet::fromSyntax('if is_mine they can view', 'documents');
WarrantRuleSet::fromSyntax('if in_team(:t) they can view', 'documents', ['t' => 'sales']);
```

`Warrant::ruleSet()` is the same call from the facade, and it lets the schema live
in the string's own header:

```php
Warrant::ruleSet('for documents { if is_mine they can view }');
```

Prefer the header. It travels with the string, so editor tooling reading your
source knows which schema to check the condition and ability names against.
Passing the schema as a PHP argument leaves the string unchecked: still valid, just
unverifiable from the outside. A header and an argument that disagree are an error.

## From already-parsed rules

```php
use Warrant\Rules\WarrantRule;

$own      = WarrantRule::fromSyntax('if is_mine they can view, update');
$noDelete = WarrantRule::fromSyntax('they cannot delete');

WarrantRuleSet::fromRules('documents', $own, $noDelete);
WarrantRuleSet::fromRules('documents', [$own, $noDelete]);   // same
```

`fromRules` takes a variadic list or a single array, flattens a mix of both,
accepts builders directly, and takes no bindings, since the rules are already
resolved.

This is also how you build an empty set, which is the right answer for a user with
no access:

```php
WarrantRuleSet::fromRules('documents');
```

## With a build callback

Each `$rule()` call starts a fresh rule and appends it. You never call `toRule()`
yourself:

```php
WarrantRuleSet::build('documents', function ($rule) {
    $rule()->if('is_mine')->theyCan('view', 'update');
    $rule()->if('is_locked')->theyCannotBecause('update', 'This document is locked.');
    $rule()->theyCannot('delete');
});
```

This is usually the most readable shape to return from a resolver when the policy
is code rather than data. The full builder surface is in
[the PHP builder](/rules/builder/).

## Straight from the parser

When you want the parsed rules without a set around them:

```php
use Warrant\DSL\Parsing\WarrantParser;

$rules = WarrantParser::parse('if is_mine they can view');        // WarrantRule[]
$one   = WarrantParser::parseSingleRule('they cannot delete');    // WarrantRule
$expr  = WarrantParser::parseConditionExpression('a or not b');   // an expression
```

## Groups, for several schemas at once

A `RuleSetGroup` holds one merged set per schema, authored together:

```php
$group = Warrant::group(<<<'WARRANT'
    for documents {
        if is_mine they can view, update
    }

    for folders {
        if is_owner they can view
    }
WARRANT);

$group->forSchema('documents');   // the WarrantRuleSet, or null
$group->schemaKeys();             // ['documents', 'folders']
count($group);
```

Blocks targeting the same schema are merged, rules concatenated in source order, so
a group holds at most one set per key.

```php
RuleSetGroup::fromSyntax($text, $bindings);
RuleSetGroup::fromFile(base_path('warrant/editor.warrant'));
RuleSetGroup::fromRuleSets($a, $b, [$c, $d]);
```

## Round-tripping

Any set or group renders back to the language:

```php
$set->toSyntax();        // canonical text, inline literals
$set->toBoundSyntax();   // text plus a positional bindings array
```

`toSyntax()` can only render parameters expressible as inline literals. An array,
an object, `NAN`, `INF`, or a float needing exponent notation throws a
`LogicException`, and `toBoundSyntax()` is the answer, since it extracts every
parameter as a positional binding.

```text
Condition parameter of type array cannot be written inline; use toBoundSyntax().
```

A closure denial message has no inline form either, so `toSyntax()` throws on one
and `toBoundSyntax()` carries it losslessly.

`@context`, `@column`, and `@sql` render as themselves in both forms and never
consume a positional binding.
