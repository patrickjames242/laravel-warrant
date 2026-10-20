---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Providing rules
description: Examples of rule providers returning rules from code, the database, and files.
sidebar:
  order: 4
---

A provider hands Warrant the rules for one user and one schema, once per request.
These examples show the shapes it can return and the places rules usually live.
[Providing rules](/supplying-rules/provider/) covers it in depth.

## A provider

```php
namespace App\Warrant;

use Warrant\Rules\RuleProvider;
use Warrant\Rules\RuleProviderContext;

class DatabaseRuleProvider implements RuleProvider
{
    public function rules(RuleProviderContext $context): iterable
    {
        return DB::table('role_rules')
            ->where('role_id', $context->user->role_id)
            ->where('schema_key', $context->schemaKey)
            ->pluck('rule');            // a collection of rule text
    }
}
```

```php
// config/warrant.php
'rule_provider' => App\Warrant\DatabaseRuleProvider::class,
```

## What it is handed

```php
$context->user;       // the Authenticatable being checked, nullable
$context->schemaKey;  // 'documents'
$context->schema;     // App\Warrant\DocumentSchema
$context->model;      // App\Models\Document, or null for a schema with no model
```

## Returning text, by role

A provider can return rule text, `warrant()` text with its values, a parsed
`WarrantSyntax`, rules, a `RuleSetNode`, or an iterable of any of these:

```php
public function rules(RuleProviderContext $context): string|iterable
{
    if ($context->user === null) {
        return [];
    }

    return match ($context->user->role) {
        'admin'  => 'they can *',
        'editor' => 'if is_mine or in_my_team they can view, update',
        default  => [],
    };
}
```

## Returning a rule set from a file

```php
use Warrant\DSL\Parsing\BoundSyntax;

public function rules(RuleProviderContext $context): BoundSyntax
{
    return warrant_file(base_path("warrant/{$context->user->role}"));
}
```

## Returning built rules

```php
use Warrant\Builders\Build;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;

public function rules(RuleProviderContext $context): RuleSetNode
{
    $teamIds = $context->user->teams->pluck('id');

    return Build::ruleSet($context->schemaKey, function ($rule) use ($teamIds) {
        $rule()->orIf(function ($c) use ($teamIds) {
            foreach ($teamIds as $id) {
                $c->orIf('in_team', [$id]);
            }
        })->theyCan('view');
    });
}
```

## Composing several sources

```php

public function rules(RuleProviderContext $context): array
{
    $key = $context->schemaKey;

    $sources = [
        'they can view',                                         // a baseline
        $this->roles->forRole($context->user->role, $key),       // the role's rules
    ];

    foreach ($context->user->teams as $team) {
        $sources[] = $this->teams->forTeam($team, $key);         // each team's rules
    }

    $sources[] = 'if is_suspended they cannot *';

    return $sources;
}
```

Order never changes the answer, so the sources join in any sequence. See
[Composing](/supplying-rules/composing/).

## Rules stored in the database

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
Warrant::parse($row->rules)->validate()->scopedTo($row->schema_key);
```

## Validating rules before saving them

```php
use Warrant\DSL\Parsing\WarrantSyntaxException;

public function store(Request $request)
{
    try {
        Warrant::parse("for {$request->string('schema_key')} {\n{$request->string('rules')}\n}")->validate();
    } catch (WarrantSyntaxException | InvalidArgumentException $e) {
        return back()->withErrors(['rules' => $e->getMessage()]);
    }

    RoleRule::create([...]);
}
```

See [Validation](/supplying-rules/validation/).

## Rules the schema contributes

A schema's own `rules()` is merged with whatever the provider returns, which makes
it the home for policy that holds for everyone:

```php
class DocumentSchema extends WarrantSchema
{
    public function rules(RuleProviderContext $context): string
    {
        return "if is_locked they cannot update because 'This document is locked.'";
    }
}
```

## When the rules change mid-request

Resolved rules are memoized per user. Flush after changing what a user holds:

```php
$user->roles()->attach($editorRole);

Warrant::flush($user);   // this user
Warrant::flush();        // everyone
```

See [Memoization](/supplying-rules/memoization/).
