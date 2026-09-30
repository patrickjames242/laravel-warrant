---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Cheat sheet
description: One page covering the whole surface.
sidebar:
  order: 11
---

## A schema

```php
class DocumentSchema extends WarrantSchema
{
    public const model = Document::class;

    #[Ability] public const VIEW = 'view';
    #[Ability(requiredContext: ['workspace_id'])] public const PUBLISH = 'publish';
    #[RequiredContext] public const TENANT = 'tenant_id';

    #[RowCondition]
    public function isMine(RowConditionContext $c): Builder
    {
        return $c->query->where($c->row('user_id'), $c->user->getAuthIdentifier());
    }

    #[GlobalCondition]
    public function isAdmin(GlobalConditionContext $c): bool
    {
        return $c->user->is_admin;
    }

    #[RuleTemplate]
    public function requiresApproval(): string
    {
        return "if not is_approved they cannot because 'Needs approval.'";
    }

    protected function implicitRules(): array|WarrantRuleSet
    {
        return [WarrantRule::fromSyntax('if is_suspended they cannot *')];
    }

    protected function defaultContext(): array
    {
        return ['tenant_id' => app(Tenancy::class)->currentId()];
    }
}
```

## Rules

```warrant
if is_mine they can view, update
if is_locked they cannot update because 'This document is locked.'
if is_admin they can *

can they view {
    if is_public they can
    if is_confidential they cannot
}

if is_mine and not is_locked they can update
if is_manager and (in_team('sales') or in_team('eng')) they can approve

if in_workspace(@context workspace_id) they can view
if matches(@column pay_period_id) they can view
if matches(@sql "select id from settings limit 1") they can view

if can(view for folders(@column folder_id)) they can view
if check(is_open for pay_periods(@context period_id)) they can submit
if can(view for folders(@context f) with tenant_id = @context t) they can view
if can(read) they can comment

@include requires_approval for view, edit
```

## Checks

```php
Warrant::can('update', $document);
Warrant::canAny(['view', 'approve'], $document);
Warrant::cannot('delete', $document);
Warrant::authorize('update', $document);
Warrant::abilities($document);
Warrant::can('create', Document::class);
Warrant::can('update', $document, ['workspace_id' => 'ws-1']);
Warrant::can('update', $document, user: $other);

$user->can('view', $document);
$user->warrant()->can('view', $document);
DocumentSchema::guard($user)->can('view', 42);
```

## Queries

```php
Document::query()->userHasAbility('view')->paginate();
Document::query()->userHasAbility(['a','b'], matchMode: AbilityMatchMode::ANY)->get();
Document::query()->selectUserAbilities(onlyAbilities: ['update'])->get();
$document->loadUserAbilities();

$guard = Warrant::forSchema(Document::class, $user);
$guard->filterQuery($query, 'view');
$guard->compileGate($query, 'view')->decision();
```

## Reachability

```php
Warrant::reachabilityOf(Document::class, 'update');   // NEVER | MAYBE | ALWAYS
Warrant::couldEverHave(Document::class, 'update');
Warrant::alwaysHas(Document::class, 'view');
Warrant::neverHas(Document::class, 'delete');
Warrant::possibleAbilities(Document::class);
Warrant::guaranteedAbilities(Document::class);
Warrant::impossibleAbilities(Document::class);
```

## Middleware

```php
WarrantMiddleware::string('document', 'view');
WarrantMiddleware::canCreate('documents');
WarrantMiddleware::guard('documents', 'view', fn () => Route::get(...));
WarrantMiddleware::couldEver('documents', 'view');
WarrantMiddleware::always('documents', 'create');
WarrantMiddleware::never('documents', 'approve');
```

## Rules as data

```php
WarrantRuleSet::fromSyntax($text, 'documents', $bindings);
WarrantRuleSet::fromRules('documents', $ruleA, $ruleB);
WarrantRuleSet::build('documents', fn ($rule) => $rule()->if('is_mine')->theyCan('view'));
WarrantRuleSet::merge($a, $b, $c);
$a->mergeWith($b);
$set->validate();
$set->toSyntax();
$set->toBoundSyntax();

RuleSetGroup::fromSyntax($text);
RuleSetGroup::fromFile(base_path('warrant/editor.warrant'));
$group->forSchema('documents');

Warrant::rule('for documents if is_mine they can view');
Warrant::condition('is_owner or is_admin');
```

## Builder

```php
WarrantRule::build()
    ->if('is_mine')
    ->orIf(fn ($c) => $c->if('is_manager')->andIf('in_region'))
    ->ifCan('view', 'folders', Ref::column('folder_id'))
    ->ifCheck('is_open', 'pay_periods', Ref::context('period_id'))
    ->theyCan('view')
    ->theyCannotBecause('delete', 'Not allowed.')
    ->toRule();

Ref::context('year');
Ref::column('pay_period_id');
Ref::column('timesheets', 'pay_period_id');
Ref::sql('select 1');
new NoRow;
```

## Introspection

```php
Warrant::forSchema(Document::class, $user)->resolvedRuleSet()->toSyntax();
Warrant::registry()->registeredSchemas();
Warrant::registry()->resolveSchemaKeyOrFail(Document::class);

DocumentSchema::abilityNames();
DocumentSchema::rowConditionKeys();
DocumentSchema::globalConditionKeys();
DocumentSchema::requiredContextKeys();
DocumentSchema::hasRows();
DocumentSchema::hasRowKey();
```

## Flushing

```php
Warrant::flush($user);   // one user
Warrant::flush();        // everyone
```

## Config

```php
'rule_resolver' => App\Warrant\DatabaseRuleResolver::class,
'schemas'       => ['documents' => App\Warrant\DocumentSchema::class],
'register_gate' => true,
```
