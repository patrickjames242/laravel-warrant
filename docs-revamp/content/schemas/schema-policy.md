---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Schema-level policy
description: Implicit rules and default context, the two places a schema is allowed to decide something.
sidebar:
  order: 6
---

A schema is vocabulary. Two hooks are the deliberate exceptions, for guarantees
that must not depend on what a resolver happened to return.

## Implicit rules

`implicitRules()` declares rules merged into every resolved rule set for this
schema, before compilation. They are validated and combine exactly like resolver
rules:

```php
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;

public function implicitRules(): array|RuleSetNode
{
    return [
        WarrantSyntax::parse('if is_admin they can *')->rule(),
        WarrantSyntax::parse('if is_suspended they cannot *')->rule(),
    ];
}
```

Return a plain list of rule entries, or a fully formed `RuleSetNode` for this schema, whichever
your baseline logic produces most naturally. A returned set must target this
schema.

Because [rule order never matters](/concepts/grants-and-denials/), an implicit
`cannot` beats any `can` a resolver supplies. That is the whole point: a suspension
lockout that cannot be undone by a badly built rule set, an admin escape hatch that
does not depend on every role definition remembering it.

They are still ordinary rules, evaluated against the current user through their
conditions. `is_suspended` is a condition like any other.

### What belongs here

Guarantees, not policy. Ask whether it would be a bug for a resolver to be able to
override it.

Good: a suspension lockout, a lockout for an unverified email, a hard denial on
soft-deleted rows, an admin escape hatch your operations team depends on.

Not good: "editors can update". That varies, it belongs in data, and putting it
here means an administrator cannot change it without a deploy.

## Default context

`defaultContext()` supplies context values so callers may omit them:

```php
protected function defaultContext(): array
{
    return ['tenant_id' => app(Tenancy::class)->currentId()];
}
```

Whatever a caller passes is merged over the defaults, key by key.

This is not a convenience. Several paths cannot pass context at all, so without a
default they silently grant nothing:

```php
Route::get('/documents/{document}', ...)
    ->middleware(WarrantMiddleware::string('document', 'view'));

Document::query()->userHasAbility('view')->get();

Route::get('/documents/{document}', ...)->middleware('can:view,document');
```

A default can satisfy a required key, so the combination you usually want is both:

```php
#[RequiredContext] public const TENANT = 'tenant_id';

protected function defaultContext(): array
{
    return ['tenant_id' => app(Tenancy::class)->currentId()];
}
```

The requirement then only ever fires when the tenant genuinely is not set up, which
is exactly when you want to hear about it. See
[requiring context](/concepts/context/requiring/).

## Denial message hooks

Two more schema-level hooks supply a message when `authorize()` denies and the
responsible rule carried none. Both are covered in [denial
messages](/rules/denial-messages/):

```php
public function forbiddenDenialMessage(WarrantDenialContext $c): string|Throwable|null;
public function ungrantedDenialMessage(WarrantUngrantedContext $c): string|Throwable|null;
```
