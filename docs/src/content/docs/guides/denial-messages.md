---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Denial messages
description: Turn a denial into an explanation — authorize(), messages on cannot rules, schema fallbacks, and the denial context.
sidebar:
  order: 7
---

`userHasAbilities` answers yes or no. When a denial should **say why**, reach for
`authorize` — its throwing sibling — and attach a message to the rule that does
the forbidding.

```php
Document::authorize('update', $document); // returns void, or throws on denial
```

On success it returns nothing; on failure it throws a
`Warrant\WarrantAuthorizationException`, which extends Laravel's
`Illuminate\Auth\Access\AuthorizationException` — so the framework renders it as a
**403** carrying the message, with no handler wiring. `authorize` takes the same
arguments as [`userHasAbilities`](/guides/checking-access/#boolean-checks): an
ability (or list), an optional target, user, [match mode](/guides/checking-access/#match-modes),
and [context](/guides/context/).

## Attaching a message to a rule

Only a `cannot` rule can carry a message, because only a `cannot` *actively
forbids*. A missing `can` is the **absence** of a grant — it names no single
rule, so there is nothing to hang a message on (that case is covered
[below](#when-nothing-granted-access)).

`withDenialMessage` lives on `WarrantRule` itself, so you can attach one to any
rule regardless of how it was authored — including a rule parsed from the string
DSL:

```php
use Warrant\RuleSyntaxTree\WarrantRule;

// On a rule parsed from syntax:
WarrantRule::fromSyntax('if is_locked they cannot update')
    ->withDenialMessage('This document is locked and can no longer be edited.');

// Or mid-chain on the fluent builder:
WarrantRule::build()
    ->if('is_locked')->theyCannot('update')
    ->withDenialMessage('This document is locked and can no longer be edited.')
    ->toRule();
```

`WarrantRule` is immutable, so `withDenialMessage` returns a **copy** carrying the
message — the original is untouched.

### Dynamic messages with a closure

The message may also be a **closure**, receiving a `WarrantDenialContext` and
returning either a string, or a `Throwable` to throw as-is:

```php
use Warrant\WarrantDenialContext;

->withDenialMessage(fn (WarrantDenialContext $c) => "You cannot edit {$c->target->title} while it is locked.")
->withDenialMessage(fn (WarrantDenialContext $c) => new DocumentLockedException($c->target))
```

Returning your own exception opts out of the automatic 403 — its own rendering
applies.

The context carries the subject and object, the schema and effective context bag,
the gate that was checked, the responsible rule, and the exact abilities it
blocked:

| Property | Type | What it is |
|---|---|---|
| `$c->user` | `Authenticatable` | The user who was denied. |
| `$c->target` | `?Model` | The row checked, or `null` for a no-target check. |
| `$c->schema` | `string` | The schema class-string. |
| `$c->context` | `array` | The effective [check-time context](/guides/context/). |
| `$c->gate` | `WarrantGate` | What was asked — `$c->gate->abilities` and `$c->gate->matchMode`. |
| `$c->rule` | `WarrantRule` | The responsible `cannot` rule. |
| `$c->deniedAbilities` | `array` | The concrete gate abilities this rule blocked, with any `*` already resolved. |

`deniedAbilities` has the wildcard expanded for you, so you never have to unpack a
`*` yourself.

## How the responsible rule is chosen

After a denial, Warrant walks the rules in resolver order ([implicit
rules](/guides/resolvers/#implicit-rules) first) and surfaces the **first
message-bearing `cannot` whose condition actually matched**. If several forbid,
the earliest one carrying a message wins.

Diagnosis runs the *same* condition SQL as the check itself, so it can never blame
a rule that didn't fire. It also works for [no-target
checks](/guides/checking-access/#no-target-checks) — there, only global
or unconditional `cannot` rules can be the cause, since a row condition can't
fire without a row.

:::note[Messages live in PHP, never in the DSL]
Messages are attached with `withDenialMessage`, never written inside rule text —
the [rule language](/guides/rule-language/) has no syntax for them (a closure
couldn't be expressed anyway), and `toSyntax()` drops any attached message. A
`withDenialMessage` on a rule with **no** `theyCannot` clause is rejected at
validation — it could never fire.
:::

## When nothing granted access

A `cannot` message explains being *forbidden*. The other way a check fails is that
**nothing granted it** — no `cannot` forbade the user, but no `can` allowed them
either. There's no rule to point at, so that message lives on the schema, in
`ungrantedDenialMessage`:

```php
use Warrant\WarrantUngrantedContext;

class DocumentSchema extends WarrantSchema
{
    protected function ungrantedDenialMessage(WarrantUngrantedContext $c): string|Throwable|null
    {
        return match (true) {
            in_array('approve', $c->ungrantedAbilities, true) => 'You need an approver role.',
            default => null, // keep the generic 403
        };
    }
}
```

`WarrantUngrantedContext` is like the denial context but with **no rule** (there
is none) and an `$c->ungrantedAbilities` list instead of `deniedAbilities` — the
concrete gate abilities that had no grant. Under `ANY` that is the whole gate (so
you can say "you need at least one of …"); under `ALL` it is just the missing
subset. Return a string (wrapped in a 403), a `Throwable`, or `null` to keep the
generic default.

## A default for message-less denials

A message-less `cannot` is still a deliberate forbid, not a lack of grant — so
rather than fall through to `ungrantedDenialMessage`, it has its own schema-level
catch, `forbiddenDenialMessage`, for a single default across many message-less
`cannot` rules:

```php
protected function forbiddenDenialMessage(WarrantDenialContext $c): string|Throwable|null
{
    return "You cannot {$c->deniedAbilities[0]} this document.";
}
```

It receives the full `WarrantDenialContext` — the responsible `$c->rule` and the
`$c->deniedAbilities` it blocked — since there *is* a rule; it just carried no
message of its own.

## Message-source precedence

Warrant tries the sources in priority order and takes the first that returns
non-null:

| Cause of the denial | Message used |
|---|---|
| a matching `cannot` **with** a message | that rule's `withDenialMessage` |
| a matching `cannot` **without** a message | schema `forbiddenDenialMessage()` |
| nothing granted the ability | schema `ungrantedDenialMessage()` |
| none of the above returned a message | generic 403 |

Forbid sources are tried **before** the ungranted source, so when abilities fail
for mixed reasons (one forbidden, one merely ungranted) the forbid wins — being
actively blocked, and by what, is the more specific answer. The ungranted hook is
reached only if no forbid is present, or the forbidden hook declines by returning
`null`.

:::tip[Route middleware surfaces these automatically]
Targeted [route middleware](/guides/middleware/) calls `authorize` under the hood,
so a `403` on a model-bound route already carries the responsible rule's message —
no extra wiring.
:::

See [Errors & exceptions](/reference/errors/) for `WarrantAuthorizationException`
and the denial-context classes, and [Grants and denials](/guides/grants-and-denials/)
for why a `cannot` is the thing that forbids.
