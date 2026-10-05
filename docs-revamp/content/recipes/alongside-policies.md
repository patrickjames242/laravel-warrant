---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Running alongside policies
description: Adopt Warrant incrementally while most authorization still lives in policies.
sidebar:
  order: 13
---

You do not have to convert everything. Warrant's Gate bridge is built so that the
two coexist, and you move one resource at a time.

## How the fall-through works

Warrant registers a `Gate::before` hook. On any Gate call it runs first and asks
whether the ability belongs to a **registered** Warrant schema. If it does, Warrant
answers. If it does not, the hook returns `null` and Laravel carries on to your
policies, gate closures, and `can:` routes.

Registration is the switch. An unregistered schema is invisible:

```php
'schemas' => [
    'documents' => App\Warrant\DocumentSchema::class,
    // 'invoices' => App\Warrant\InvoiceSchema::class,   // not yet
],
```

So `$user->can('view', $document)` goes to Warrant and `$user->can('view',
$invoice)` goes to `InvoicePolicy`, with no code in your controllers knowing which.

## An order that works

**1. Pick one resource.** The best first candidate has an index endpoint with a
hand-written visibility query beside the policy, because that duplication is what
you are removing.

**2. Write the schema, do not register it.** Conditions and abilities only. Nothing
changes yet.

**3. Write the rules and a resolver** covering just that schema:

```php
public function resolve(RuleResolutionContext $context): RuleSetNode
{
    return match ($context->schemaKey) {
        'documents' => $this->documentRules($context),
        default => RuleSetNode::fromRules($context->schemaKey),
    };
}
```

**4. Shadow the policy.** Run both, return the old answer, log disagreements. The
code is in [migrating one policy](/recipes/migrating-a-policy/).

**5. Register the schema** once the log is quiet, and delete the policy.

**6. Move to the next resource.**

## Two abilities with the same name

`view` on `documents` and `view` on `invoices` are separate abilities that happen
to share a name, and the bridge keys on the target rather than the name, so there
is no collision.

Where you can get one is a gate ability with no model target:

```php
Gate::define('access-admin', fn (User $user) => $user->is_admin);
```

```php
$user->can('access-admin');
```

If you later declare `access-admin` on a registered Warrant schema, the hook starts
answering and your closure stops running. Nothing warns you. When converting
target-free gates, grep for the ability name.

## Turning the bridge off

If you would rather reach Warrant explicitly while you migrate:

```php
// config/warrant.php
'register_gate' => false,
```

Then `$user->can(...)` always goes to your policies, and Warrant is reached only
through the facade, the guards, the scopes, and the `warrant` middleware:

```php
Warrant::can('view', $document);
Document::query()->userHasAbility('view')->get();
```

That is a legitimate end state, not just a migration step. It makes every Warrant
check visible in the diff.

## Route middleware during a migration

Both work, and they can sit on adjacent routes:

```php
Route::get('/documents/{document}', ...)
    ->middleware(WarrantMiddleware::string('document', 'view'));   // Warrant

Route::get('/invoices/{invoice}', ...)
    ->middleware('can:view,invoice');                              // policy
```

`can:` routes for a converted resource keep working through the bridge, so you do
not have to rewrite routes at the same time as the policy.

## Where the two models differ

Worth knowing before you start, because they shape how much of a policy survives
translation.

**Warrant needs a predicate, not a decision.** A policy method can do anything. A
condition has to produce a query constraint, a `bool`, or `null`. A policy method
calling three services and reading two relations may need restructuring rather than
translating. See [what rules cannot do](/concepts/what-rules-cannot-do/).

**Warrant has no `before` of its own.** A policy `before()` method granting admins
everything becomes `if is_admin they can *`, ideally in
[`implicitRules()`](/schemas/schema-policy/).

**Warrant denies by default and cannot be appealed.** A policy returning `null` to
abstain has no equivalent, since silence already means denial, and a `cannot`
cannot be undone by a later grant.

**Warrant needs a user.** Guests always fall through the bridge. If a resource has
real anonymous access, that stays a policy or a gate closure, or you give guests a
null-object user.

## Tests during the overlap

Keep the policy tests and add a parity test, so a divergence fails CI rather than
appearing in a log:

```php
it('agrees with the policy on every seeded document', function () {
    $policy = new DocumentPolicy;

    foreach (Document::all() as $document) {
        foreach (['view', 'update', 'delete'] as $ability) {
            expect(Warrant::can($ability, $document, user: $user))
                ->toBe($policy->{$ability}($user, $document), "{$ability} on {$document->id}");
        }
    }
});
```

Delete it with the policy.
