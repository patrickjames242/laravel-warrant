---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Denial messages
description: Turn a denial into an explanation — authorize(), messages on cannot rules, schema fallbacks, and the denial context.
sidebar:
  order: 7
---

`Warrant::can` answers yes or no. When a denial should **say why**, reach for
`Warrant::authorize` — its throwing sibling — and attach a message to the rule
that does the forbidding.

```php
Warrant::authorize('update', $document); // returns void, or throws on denial
```

On success it returns nothing; on failure it throws a
`Warrant\WarrantAuthorizationException`, which extends Laravel's
`Illuminate\Auth\Access\AuthorizationException` — so the framework renders it as a
**403** carrying the message, with no handler wiring. `authorize` takes the same
arguments as [`Warrant::can`](/guides/checking-access/#boolean-checks): an
ability (or list), an optional target, [context](/guides/context/), and user.
Use `authorizeAny` when *any* of several abilities should satisfy the check
(`authorize` requires all).

## Attaching a message to a rule

Only a `cannot` clause can carry a message, because only a `cannot` *actively
forbids*. A missing `can` is the **absence** of a grant — it names no single
rule, so there is nothing to hang a message on (that case is covered
[below](#when-nothing-granted-access)). Each `cannot` clause carries its own
message, so a rule that denies several abilities can give each its own reason.

### In the string DSL

A `cannot` clause carries its message inline, with `because` followed by a
string literal:

```
if is_locked
they cannot update because 'This document is locked and can no longer be edited.'
```

`because` is valid **only** immediately after a `cannot` clause — never after
`can`. A `@context` reference is not accepted here: a message is fixed when the
rule is parsed, not resolved per check.

Different abilities under the same `if` can give different reasons — each
`they cannot ...` clause is its own denial, all on one rule:

```
if is_locked
they cannot update because 'This document is locked and can no longer be edited.'
they cannot delete because 'Locked documents cannot be deleted.'
```

The message can also come from a `:name`/`?` [binding](/guides/rule-language/#bindings)
instead of a literal, and that binding may resolve to a **string or a closure** —
so even the [dynamic closure form](#dynamic-messages-with-a-closure) can be
carried through `fromSyntax`:

```php
WarrantRule::fromSyntax('if is_locked they cannot update because :msg', bindings: [
    'msg' => fn (WarrantDenialContext $c) => "You cannot edit {$c->target->title} while it is locked.",
]);
```

### With the fluent builder

`theyCannotBecause` attaches a message in the same call — one clause per call, so
separate calls give separate abilities separate messages, and abilities passed
together share one message:

```php
use Warrant\RuleSyntaxTree\WarrantRule;

WarrantRule::build()
    ->if('is_locked')
    ->theyCannotBecause('update', 'This document is locked and can no longer be edited.')
    ->theyCannotBecause('delete', 'Locked documents cannot be deleted.')
    ->toRule();

// several abilities sharing one message:
WarrantRule::build()
    ->if('is_locked')
    ->theyCannotBecause(['update', 'delete'], 'This document is locked.')
    ->toRule();
```

`theyCannot(...)` (message-less) stays available for plain denials.

### On an existing rule

`withDenialMessage` lives on `WarrantRule` itself, so you can add a message to a
rule however it was authored — notably a `fromSyntax` rule. It applies to every
denied ability by default, or pass a list of abilities to scope it:

```php
WarrantRule::fromSyntax('if is_locked they cannot update, delete')
    ->withDenialMessage('This document is locked.');            // both abilities

WarrantRule::fromSyntax('if is_locked they cannot update, delete')
    ->withDenialMessage('Deletes are permanent.', ['delete']);  // just delete
```

`WarrantRule` is immutable, so `withDenialMessage` returns a **copy** — the
original is untouched. It can only target abilities the rule denies; messaging an
ability the rule does not `cannot`, or any rule with no `cannot` clause, throws.

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
| `$c->deniedAbilities` | `array` | The concrete gate abilities blocked by the *same message* that fired, with any `*` already resolved — so a per-clause message sees only the abilities it explains. |

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

:::note[Where messages can live]
Inline in the DSL with [`because`](#in-the-string-dsl), on the
[fluent builder](#with-the-fluent-builder) with `theyCannotBecause`, or on an
[existing rule](#on-an-existing-rule) with `withDenialMessage`. A message always
rides on a `cannot`, so attaching one to a rule with **no** `cannot` clause (or to
an ability the rule does not deny) throws immediately.

Round-tripping: `toSyntax()` re-renders a string message as `because '...'` but
**throws** on a closure message (no inline form); `toBoundSyntax()` carries
either form losslessly, as a `?` binding.
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
    public function ungrantedDenialMessage(WarrantUngrantedContext $c): string|Throwable|null
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
public function forbiddenDenialMessage(WarrantDenialContext $c): string|Throwable|null
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
