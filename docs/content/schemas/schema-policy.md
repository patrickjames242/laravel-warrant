---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Schema-level policy
description: Schema rules and default context, the two places a schema is allowed to decide something.
sidebar:
  order: 6
---

A schema is vocabulary. Two hooks are the deliberate exceptions: rules the schema
supplies itself, and context it fills in for callers.

## Schema rules

`rules()` returns rules merged ahead of whatever the
[global provider](/supplying-rules/provider/) returns, before compilation. With no
global provider configured, they are this schema's only rules. Either way they are
validated and combine exactly like provider rules:

```php
use Warrant\Rules\RuleProviderContext;

public function rules(RuleProviderContext $context): string
{
    return '
        if is_admin they can *
        if is_suspended they cannot *
    ';
}
```

It may return [any form a provider may](/supplying-rules/provider/#what-you-may-return):
rule text, a `RuleSetNode`, a single rule entry, or an array or collection of
those. A returned rule set must target this schema.

It is handed the same `RuleProviderContext` as the global provider, so the rules
may depend on who is asking:

```php
public function rules(RuleProviderContext $context): iterable
{
    return DB::table('document_grants')
        ->where('user_id', $context->user?->getAuthIdentifier())
        ->pluck('rule');
}
```

Because [rule order never matters](/concepts/grants-and-denials/), a schema's
`cannot` beats any `can` a provider supplies. That is the whole point: a suspension
lockout that cannot be undone by a badly built rule set, an admin escape hatch that
does not depend on every role definition remembering it.

They are still ordinary rules, evaluated against the current user through their
conditions. `is_suspended` is a condition like any other.

### What belongs here

With a global provider in place: guarantees, not policy. Ask whether it would be a
bug for the provider to be able to override it.

Good: a suspension lockout, a lockout for an unverified email, a hard denial on
soft-deleted rows, an admin escape hatch your operations team depends on.

Not good: "editors can update". That varies, it belongs in data, and putting it
here means an administrator cannot change it without a deploy.

Without a global provider, this is where all of the schema's rules live, so the
line moves: policy belongs here too, ideally read from data through the context as
above rather than written into the class.

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
