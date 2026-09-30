---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Where rules live
description: Heredocs, the database, and .warrant files, with the trade-offs of each.
sidebar:
  order: 4
---

Rules are strings or built objects, and Warrant does not care where they came from.
Four homes are common, and they suit different things.

## In PHP, as a heredoc

Label the heredoc `WARRANT` and editors highlight it:

```php
$rules = Warrant::ruleSet(<<<'WARRANT'
    for documents {
        if is_mine or in_my_team they can view, update
        if is_locked they cannot update because 'This document is locked.'
    }
WARRANT);
```

Use the nowdoc form with quoted `'WARRANT'` unless you actually want PHP
interpolation, which you usually do not: values belong in
[bindings](/rules/values/), not interpolated into rule text, where a quote in a
value would end a string literal early.

Good for: policy that belongs in your repository, is reviewed in pull requests, and
changes with deploys. Not good for: anything an administrator edits.

## In the database

One row per role, per tenant, per user, whatever your model is:

```php
Schema::create('role_rules', function (Blueprint $table) {
    $table->id();
    $table->string('role');
    $table->string('schema_key');
    $table->text('rules');
    $table->timestamps();
});
```

```php
WarrantRuleSet::fromSyntax($row->rules, $row->schema_key);
```

Good for: policy that differs per tenant, or that an administrator edits through a
UI. See [an admin permissions UI](/recipes/admin-ui/).

The cost is that a typo in a stored rule is a runtime error rather than a compile
error, so [validate them](/supplying-rules/validation/) on write and in CI.

## In `.warrant` files

A standalone file of `for <schema> { ... }` blocks, read from disk:

```warrant
# warrant/editor.warrant

for documents {
    if is_mine or in_my_team they can view, update
    if is_locked they cannot update because 'This document is locked.'
}

for folders {
    if is_member they can view
}
```

```php
use Warrant\Rules\RuleSetGroup;

$group = RuleSetGroup::fromFile(base_path('warrant/editor.warrant'));

$group->forSchema('documents');   // the WarrantRuleSet for one schema
$group->schemaKeys();             // ['documents', 'folders']
```

Bindings work the same way:

```php
RuleSetGroup::fromFile(base_path('warrant/editor.warrant'), ['region' => 'west']);
```

An unreadable path throws:

```text
Cannot read Warrant rule file [/app/warrant/editor.warrant].
```

Good for: policy in version control that is long enough to deserve its own file,
and for the case where you want the [editor
tooling](/editors/overview/) working on it. `.warrant` files are what the tooling
targets by extension, so they get highlighting everywhere with no configuration.

A resolver built on them:

```php
public function resolve(RuleResolutionContext $context): WarrantRuleSet
{
    $path = base_path("warrant/{$context->user->role}.warrant");

    if (! is_file($path)) {
        return WarrantRuleSet::fromRules($context->schemaKey);
    }

    return RuleSetGroup::fromFile($path)->forSchema($context->schemaKey)
        ?? WarrantRuleSet::fromRules($context->schemaKey);
}
```

In production, cache the parse rather than reading and parsing per request:

```php
$group = Cache::rememberForever(
    "warrant.rules.{$role}." . filemtime($path),
    fn () => RuleSetGroup::fromFile($path),
);
```

## As built objects

No text at all, when the shape depends on runtime data:

```php
WarrantRuleSet::build('documents', function ($rule) use ($teamIds) {
    $rule()->orIf(function ($c) use ($teamIds) {
        foreach ($teamIds as $id) {
            $c->orIf('in_team', [$id]);
        }
    })->theyCan('view');
});
```

Good for: rules whose parameters are values, especially arrays and objects, which
have no inline form in the language.

## Mixing them

Nothing stops you. [Composing from several
sources](/supplying-rules/composing/) is the page for that, and merging is safe
regardless of where each piece came from.

## Round-tripping between homes

A group renders back to text, so moving policy from one home to another is
mechanical:

```php
$group->toSyntax();        // for <schema> { ... } blocks
$group->toBoundSyntax();   // the same, parameterized, plus a bindings array
```

That is also how you would write a migration that moves rules from files into a
table, or dump a tenant's stored rules into a file to review them.
