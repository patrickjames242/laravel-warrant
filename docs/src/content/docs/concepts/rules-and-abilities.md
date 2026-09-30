---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Rules, abilities, and subjects
description: The three nouns the rest of the documentation leans on.
sidebar:
  order: 1
---

Three nouns, used precisely throughout.

**The subject** is whoever is being asked about. In a rule, the word is `they`.
Your resolver was handed a user and returned rules describing what *that* user may
do, so a rule set is always one person's policy, never everybody's.

**An ability** is a verb you can check for. `view`, `update`, `approve`, `submit`,
`unlock`. Abilities are declared per schema as class constants:

```php
#[Ability] public const VIEW    = 'view';
#[Ability] public const APPROVE = 'approve';
```

There is no global registry of abilities and no fixed vocabulary. `approve` on
`documents` and `approve` on `timesheets` are separate abilities that happen to
share a name. A rule naming an ability the schema does not declare is rejected:

```text
Ability [aprove] is not declared by the schema.
```

**A rule** grants or denies abilities, optionally under a condition:

```warrant
if is_mine they can view, update
```

That is one rule with one `if` and one clause. This is two rules:

```warrant
if is_mine they can view, update
if is_locked they cannot update
```

Every `if` starts a new rule. Clauses attach to the most recent `if` above them,
and clauses written before any `if` form a leading unconditional rule:

```warrant
they can view                 # unconditional: every row

if is_mine
they can update               # conditional
they cannot delete            # also attached to is_mine
```

## Rule sets and groups

A `WarrantRuleSet` is the rules for one schema. It carries the schema key, so a
set built for `documents` cannot be compiled against `folders`:

```php
WarrantRuleSet::fromSyntax('if is_mine they can view', 'documents');
```

A `RuleSetGroup` is several of those authored together, one block per schema:

```warrant
for documents {
    if is_mine they can view, update
}

for folders {
    if is_owner they can view
}
```

```php
$group = Warrant::group($syntax);
$group->forSchema('documents');   // the WarrantRuleSet
$group->schemaKeys();             // ['documents', 'folders']
```

Groups are what a [`.warrant` file](/editors/warrant-files/) holds, and what
`RuleSetGroup::fromFile()` reads.

## Who supplies them

Your [resolver](/supplying-rules/resolver/) does, per user and per schema, at
request time. Warrant has no opinion about where they come from. A table, a role
lookup, a JWT claim, a file on disk, a fluent builder, or a literal string in your
resolver are all fine.
