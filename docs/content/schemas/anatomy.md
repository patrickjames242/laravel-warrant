---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Schema anatomy
description: Every part of a schema and what each part is for.
sidebar:
  order: 1
---

A schema is a `Warrant\Schema\WarrantSchema` subclass, one per resource. Here is a
realistic one with every part in it, as a map for the rest of this section.

```php
namespace App\Warrant;

use App\Models\Document;
use Illuminate\Contracts\Database\Query\Builder;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\Facades\Warrant;
use Warrant\Schema\Ability;
use Warrant\Schema\Conditions\GlobalConditionContext;
use Warrant\Schema\DerivedCondition;
use Warrant\Schema\Conditions\RowConditionContext;
use Warrant\Schema\GlobalCondition;
use Warrant\Schema\RequiredContext;
use Warrant\Schema\RowCondition;
use Warrant\Schema\RuleTemplate;
use Warrant\Schema\WarrantSchema;

class DocumentSchema extends WarrantSchema
{
    // 1. What model these rows are.
    public const model = Document::class;

    // 2. The verbs a rule may grant or deny.
    #[Ability] public const VIEW    = 'view';
    #[Ability] public const UPDATE  = 'update';
    #[Ability] public const DELETE  = 'delete';
    #[Ability(requiredContext: ['workspace_id'])] public const PUBLISH = 'publish';

    // 3. A context key every check must supply.
    #[RequiredContext] public const TENANT = 'tenant_id';

    // 4. The questions a rule may ask about a row.
    #[RowCondition]
    public function isMine(RowConditionContext $c): Builder
    {
        return $c->query->where($c->row('user_id'), $c->user->getAuthIdentifier());
    }

    #[RowCondition]
    public function isLocked(RowConditionContext $c): Builder
    {
        return $c->query->where($c->row('locked'), true);
    }

    // 5. A question about the user rather than a row.
    #[GlobalCondition]
    public function isAdmin(GlobalConditionContext $c): bool
    {
        return $c->user->role === 'admin';
    }

    // 6. A question answered by composing other questions.
    #[DerivedCondition]
    public function isEditable(): string
    {
        return 'is_mine and not is_locked';
    }

    // 7. A reusable shape a rule may expand.
    #[RuleTemplate]
    public function requiresApproval(): string
    {
        return "if not is_approved they cannot because 'This needs approval first.'";
    }

    // 8. Rules merged into every resolved set, whatever the provider returned.
    public function rules(RuleProviderContext $context): array|RuleSetNode
    {
        return [WarrantSyntax::parse('if is_suspended they cannot *')->rule()];
    }

    // 9. Context values callers may omit.
    protected function defaultContext(): array
    {
        return ['tenant_id' => app(Tenancy::class)->currentId()];
    }
}
```

Items 1 to 7 are vocabulary. Items 8 and 9 are the two places a schema is allowed
to be policy, and they are deliberate. See [schemas as
vocabulary](/concepts/schemas-as-vocabulary/).

## The model constant

`const model` binds the schema to an Eloquent model, and the model names the schema
back through `HasWarrantSchema`. Warrant checks that they agree the first time it
resolves the schema and throws if they do not:

```text
Model [App\Models\Document] names schema [App\Warrant\FolderSchema], but that
schema names model [App\Models\Folder]; a schema and its model must name each other.
```

One model has exactly one schema. A base schema may be extended, but each concrete
schema needs its own model. That catches a common mistake: `PublishedDocument
extends Document` inherits `warrantSchema()`, which names `DocumentSchema`, whose
model is `Document`. Warrant throws. A subclass needing its own authorization needs
its own schema.

`const model = ''` means no model at all, which is a
[capability schema](/schemas/row-source/).

## The schema key

The key identifies the resource in rules, lookups, and middleware. It is not
declared on the schema. It is the key the schema is registered under in
`config/warrant.php`, which is its single source of truth:

```php
DocumentSchema::schemaKey();   // 'documents'
```

Because the key lives in the config index, that method is a lookup and needs a
booted application.

:::tip[Why the key is not derived from the table]
Deriving it would mean building the schema index instantiated every registered
model, loading and booting hundreds of Eloquent models on the first check of every
request. Declaring the key in config makes registration a plain array of strings,
so nothing is loaded until a schema is actually used.
:::

## Reading a schema back

```php
DocumentSchema::abilityNames();          // declaration order, not sorted
DocumentSchema::abilityDefinitions();    // AbilityDefinition[] { name, requiredContext }
DocumentSchema::conditionKeys();         // sorted
DocumentSchema::rowConditionKeys();      // sorted
DocumentSchema::globalConditionKeys();   // sorted
DocumentSchema::requiredContextKeys();   // schema-wide required keys
DocumentSchema::ruleTemplateKeys();
DocumentSchema::hasRows();
DocumentSchema::hasRowKey();
```

Ability order is preserved because it is also the order abilities appear in the
per-row [ability column](/checking/abilities/). Condition keys come back sorted.

## The static guard

Every schema inherits a shortcut to its own user-bound guard:

```php
DocumentSchema::guard($user)->can('update', $document);
// identical to Warrant::forSchema(DocumentSchema::class, $user)
```

That is the only user-facing entry point on the schema itself. Everything
user-scoped lives on the guard, because a schema holds no user.
