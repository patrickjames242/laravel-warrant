---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Schemas
description: Examples of declaring abilities, conditions, context, templates, and schema rules.
sidebar:
  order: 1
---

A schema is the vocabulary for one resource: the abilities a rule may grant and
the conditions a rule may test. These examples are the shapes you will write most.
[Defining schemas](/schemas/anatomy/) covers each one in depth.

## A minimal schema

```php
namespace App\Warrant;

use App\Models\Document;
use Illuminate\Contracts\Database\Query\Builder;
use Warrant\Schema\Abilities\Ability;
use Warrant\Schema\Conditions\Row\RowConditionContext;
use Warrant\Schema\Conditions\Row\RowCondition;
use Warrant\Schema\Types\Classes\ModelSchema;

class DocumentSchema extends ModelSchema
{
    public const model = Document::class;

    #[Ability] public const VIEW   = 'view';
    #[Ability] public const UPDATE = 'update';

    #[RowCondition]
    public function isMine(RowConditionContext $c): Builder
    {
        return $c->query->where($c->column('user_id'), $c->user->getAuthIdentifier());
    }
}
```

The method `isMine` is the condition `is_mine` in a rule. Register the schema in
`config/warrant.php` and point the model at it:

```php
// config/warrant.php
'schemas' => [App\Warrant\DocumentSchema::class],
```

```php
use Warrant\HasWarrantSchema;

class Document extends Model
{
    use HasWarrantSchema;

    public static function warrantSchema(): string
    {
        return \App\Warrant\DocumentSchema::class;
    }
}
```

## Abilities

```php
use Warrant\Schema\Abilities\Ability;
use Warrant\Schema\Abilities\StandardAbilities;

#[Ability] public const VIEW    = StandardAbilities::VIEW;   // 'view'
#[Ability] public const APPROVE = 'approve';
#[Ability] public const UNLOCK  = 'unlock';
```

Declaration order is the order `selectUserAbilities()` lists them in. See
[Abilities](/schemas/abilities/).

## Row conditions

A row condition narrows which rows the user matches, by adding where clauses.

```php
#[RowCondition]
public function inMyTeam(RowConditionContext $c): Builder
{
    return $c->query->whereExists(fn ($sub) => $sub
        ->from('team_members')
        ->whereColumn('team_members.team_id', $c->column('team_id'))
        ->where('user_id', $c->user->getAuthIdentifier()));
}

#[RowCondition]
public function isLocked(RowConditionContext $c): Builder
{
    return $c->query->where($c->column('locked'), true);
}
```

## Conditions with arguments

Extra parameters are the condition's arguments, so `in_team('sales')` passes
`'sales'`:

```php
#[RowCondition]
public function inTeam(RowConditionContext $c, string $team): Builder
{
    return $c->query->where($c->column('team_id'), $team);
}

#[RowCondition]
public function inAnyTeam(RowConditionContext $c, string ...$teams): Builder
{
    return $c->query->whereIn($c->column('team_id'), $teams);
}
```

## Global conditions

A global condition asks about the user, not a row, and answers with a bool:

```php
use Warrant\Schema\Conditions\Global\GlobalConditionContext;
use Warrant\Schema\Conditions\Global\GlobalCondition;

#[GlobalCondition]
public function isAdmin(GlobalConditionContext $c): bool
{
    return $c->user->role === 'admin';
}
```

## Answering unknown

Return `null` when a condition cannot answer. Unknown grants nothing and lifts no
denial:

```php
#[GlobalCondition]
public function inBillingPeriod(GlobalConditionContext $c): ?bool
{
    $period = $c->context['period'] ?? null;

    return $period === null ? null : $period === $c->user->billing_period;
}
```

## Answering in PHP when you hold the row

```php
#[RowCondition]
public function isMine(RowConditionContext $c): Builder|bool
{
    if ($c->model !== null) {
        return $c->model->user_id === $c->user->getAuthIdentifier();
    }

    return $c->query->where($c->column('user_id'), $c->user->getAuthIdentifier());
}
```

## Derived conditions

A derived condition is built from other conditions, as rule text, a parsed
`WarrantSyntax`, or a condition, `can` or `check` builder. Rule text returned as a
string may write the method's parameters as bindings, by name:

```php
use Warrant\Builders\Build;
use Warrant\Builders\CrossSchemaCheckBuilder;
use Warrant\Builders\Ref;
use Warrant\Schema\Conditions\Derived\DerivedCondition;

#[DerivedCondition]
public function isEditable(): string
{
    return 'is_mine and not is_locked';
}

#[DerivedCondition]
public function ownedOrInTeam(mixed $team): string
{
    return 'is_mine or in_team(:team)';
}

#[DerivedCondition]
public function parentIsOwned(): CrossSchemaCheckBuilder
{
    return Build::check('is_owner')->for(FolderSchema::class, Ref::column('parent_id'))->as('parent');
}
```

See [Conditions beyond SQL](/schemas/conditions-beyond-sql/).

## Context

Context is the values a check supplies alongside the user and the row. A schema can
require keys, per schema or per ability, and supply defaults:

```php
use Warrant\Schema\Context\RequiredContext;

#[RequiredContext] public const TENANT = 'tenant_id';

#[Ability(requiredContext: ['workspace_id'])] public const PUBLISH = 'publish';

#[RowCondition]
public function inCurrentWorkspace(RowConditionContext $c): Builder
{
    return $c->query->where($c->column('workspace_id'), $c->context['workspace_id']);
}

public function defaultContext(): array
{
    return ['tenant_id' => app(Tenancy::class)->currentId()];
}
```

See [Context](/concepts/context/).

## Rule templates

A template is a reusable piece of policy, expanded with `@include`. Its can/cannot clauses
name no abilities, because the `@include` names them:

```php
use Warrant\Schema\RuleTemplates\RuleTemplate;

#[RuleTemplate]
public function requiresApproval(): string
{
    return "if not is_approved they cannot because 'This needs approval first.'";
}
```

```warrant
@include requires_approval for view, edit
```

See [Rule templates](/rules/templates/).

## Rules the schema always contributes

Rules a schema returns from `rules()` are merged into every resolved set,
whatever the provider returned:

```php
use Warrant\Rules\RuleProviderContext;

public function rules(RuleProviderContext $context): string
{
    return 'if is_suspended they cannot *';
}
```

## A schema with no rows

A `RowlessSchema` has only no-target abilities, answered by global conditions:

```php
class SettingsSchema extends RowlessSchema
{
    #[Ability] public const MANAGE = 'manage';

    #[GlobalCondition]
    public function isAdmin(GlobalConditionContext $c): bool
    {
        return (bool) $c->user->is_admin;
    }
}
```

```php
Warrant::can('manage', 'settings');
```

See [Checking with no row](/checking/no-row/).
