---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Quick start
description: The smallest end-to-end Warrant setup — a schema, a rule, a resolver, and a check.
sidebar:
  order: 2
---

This is the smallest working Warrant setup: one schema, one rule, one resolver,
and the checks that use them. We'll gate a `Document` model so a user can only
view and update their own rows.

## 1. Define a schema

A schema declares the *vocabulary* for one resource — the abilities that exist
and the conditions a rule may test. It doesn't decide anything; it just teaches
each condition how to emit SQL.

```php
namespace App\Warrant;

use App\Models\Document;
use Illuminate\Contracts\Database\Query\Builder;
use Warrant\Ability;
use Warrant\Schema\Conditions\RowConditionContext;
use Warrant\Schema\WarrantSchema;
use Warrant\RowCondition;

class DocumentSchema extends WarrantSchema
{
    public const model = Document::class;

    #[Ability] public const VIEW   = 'view';
    #[Ability] public const UPDATE = 'update';

    // A row condition narrows WHICH rows the user matches.
    // The method name `isSelf` becomes the rule name `is_self`.
    #[RowCondition]
    public function isSelf(RowConditionContext $c): Builder
    {
        return $c->query->whereRaw(
            'documents.user_id = ?',
            [$c->user->getAuthIdentifier()],
        );
    }
}
```

## 2. Write a rule

Rules are plain strings in Warrant's [rule language](/guides/rule-language/).
This one grants view and update on the user's own rows:

```text
if is_self they can view, update
```

## 3. Write a resolver

The resolver is the glue between *your* access model and Warrant. At request
time it returns the rules that apply to the current user for a given resource.
Warrant ships no default — this small class is required.

```php
namespace App\Warrant;

use Warrant\RuleResolutionContext;
use Warrant\RuleResolver;
use Warrant\RuleSyntaxTree\WarrantRuleSet;

class DatabaseRuleResolver implements RuleResolver
{
    public function resolve(RuleResolutionContext $context): WarrantRuleSet
    {
        // In a real app you'd look these rules up per user/role/tenant.
        // Here we return the same rule for everyone, for the documents schema.
        return WarrantRuleSet::fromSyntax(
            'if is_self they can view, update',
            $context->schemaKey,
        );
    }
}
```

## 4. Wire it up

Point Warrant at the resolver and register the schema in `config/warrant.php`:

```php
return [
    'rule_resolver' => App\Warrant\DatabaseRuleResolver::class,
    'schemas'       => [App\Warrant\DocumentSchema::class],
];
```

Add the trait to the model and tell it which schema governs it:

```php
use Illuminate\Database\Eloquent\Model;
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

## 5. Ask about the user's access

With the rule set in place, ask whatever you need about the current user's access.
Every call below runs off that same rule, compiled to SQL. Every check defaults to
the currently authenticated user, but you can pass any user explicitly to check on
their behalf:

```php
use Warrant\Facades\Warrant;

// A single boolean value representing whether or not the current user can
// view this document
Warrant::can('view', $document);
Warrant::can('view', $document, user: $user);

// A scope that filters a list of documents by whether or not the user has the
// ability to view them
Document::query()->userHasAbility('view')->paginate();
Document::query()->userHasAbility('view', $user)->paginate();

// selectUserAbilities adds a json column to every row that looks something like
// ["view", "update"] so you know what the user can 'do' to every document
Document::query()->selectUserAbilities()->get();
Document::query()->selectUserAbilities($user)->get();

// A no-target check: can the user create documents unconditionally? (no specific row)
Warrant::can('create', Document::class);
Warrant::can('create', Document::class, user: $user);

// Every no-target ability the user has unconditionally, e.g. ['create']
Warrant::abilities(Document::class);
Warrant::abilities(Document::class, user: $user);

// middleware to guard your routes (uses the request's authenticated user)
WarrantMiddleware::guard('document', 'view', function () {
    Route::get('/documents/{document}', [DocumentController::class, 'index']);
});
```

For everyday yes/no checks, reach for Laravel's Gate — Warrant integrates with it,
so the calls you already know just work:

```php
$user->can('view', $document);          // and $user->cannot(), canAny()
Gate::authorize('view', $document);     // throws Warrant's denial message
```

`@can` and the `can:` route middleware go through the same hook. See
[Checking access](/guides/checking-access/#laravels-gate).

When you need to pass [context](/guides/context/), or you're checking the same
schema over and over, a bound guard reads better. Add the `AuthorizesWithWarrant`
trait to your `User` model for `$user->warrant()`, or call the schema's own
`guard()` static:

```php
// user-bound guard (requires `use Warrant\AuthorizesWithWarrant;` on the User model)
$user->warrant()->can('approve', $document, context: ['region' => 'us']);

// schema-bound guard — the target is just the row
DocumentSchema::guard($user)->can('view', $document);
DocumentSchema::guard($user)->abilities($document); // ['view', 'update']
```

That's the whole loop. From here:

- [Core concepts](/getting-started/core-concepts/) — how the pieces fit together.
- [Schemas](/guides/schemas/) — abilities, conditions, and context keys in depth.
- [The rule language](/guides/rule-language/) — the full DSL.
- [Checking access](/guides/checking-access/) — every way to ask about access.
- [Route middleware](/guides/middleware/) — guarding routes, targeted and no-target.
