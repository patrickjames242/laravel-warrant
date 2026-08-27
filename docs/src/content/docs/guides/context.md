---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Check-time context
description: Context keys, the @context reference, defaults, and how a missing optional key fails closed.
sidebar:
  order: 9
---

Some values a rule needs aren't known when the schema is written *or* when the
resolver builds the rules — they're known only at the moment of the check: the
current tenant, an academic year, an as-of date, an impersonated user. These are
**context keys**.

## Using context keys

A context key needs **no declaration to be used**. A rule may reference any key
with `@context <key>`, and a condition may read `$c->context['<key>']` freely —
neither requires the key to be declared anywhere on the schema.

Declaration is *only* about making a key **required** — forcing it to be present
at check time. There are two ways to do that.

A **schema-wide** required key with `#[RequiredContext]` — no check on this
resource resolves without the frame. The constant's *value* is the key string;
its name is irrelevant to Warrant:

```php
use Warrant\RequiredContext;

#[RequiredContext] public const WORKSPACE = 'workspace_id';
```

A **per-ability** required key with `#[Ability(requiredContext: [...])]` — the
key is required only when *that* ability is checked:

```php
use Warrant\Ability;

#[Ability(requiredContext: ['workspace_id'])] public const PUBLISH = 'publish';
```

```php
DocumentSchema::requiredContextKeys(); // ['workspace_id']  (the #[RequiredContext] values)
```

## Two ways a condition reads context

### 1. `@context` in the rule

A rule references a key with `@context <key>`; the value is passed to the
condition positionally at check time, just like any other argument:

```text
if in_workspace(@context workspace_id) they can view, edit
```

```php
#[RowCondition]
public function inWorkspace(RowConditionContext $c, mixed $workspace): Builder
{
    // $workspace is supplied at the check via @context workspace_id. Type it as
    // mixed or a nullable type: an absent @context key arrives as null.
    return $c->query->where('documents.workspace_id', $workspace);
}
```

A `@context` reference needs no declaration — any key name is accepted, and a key
that isn't in the effective context simply arrives as `null`. Unlike `:name` / `?`
bindings, a `@context` reference is
**not** subject to the parse-time "every binding used / no mixing" rules — it
carries no value at parse time, may sit alongside literals and bindings, and never
consumes a positional `?`:

```text
if scoped_to('projects', @context project_id, :region) they can view
```

When you build rules with the [fluent builder](/guides/rule-builder/) instead of
the string DSL, pass a `Warrant\RuleSyntaxTree\ContextRef` in the condition's
parameters wherever the DSL would write `@context <key>`. It stays symbolic in the
compiled rule and is filled per check, exactly like the `@context` form:

```php
use Warrant\RuleSyntaxTree\ContextRef;

WarrantRule::build()
    ->if('scoped_to', ['projects', new ContextRef('project_id'), $region])
    ->theyCan('view')
    ->toRule();
```

### 2. The ambient `$c->context` bag

Every condition **also** receives the full effective context on `$c->context`,
whether or not the rule passed a value via `@context`. Reach into it directly when
a condition is inherently tied to the frame — then the rule needn't mention the
key:

```php
#[RowCondition]
public function inCurrentWorkspace(RowConditionContext $c): Builder
{
    // Rule is just `if in_current_workspace they can view` — no @context needed.
    return $c->query->where('documents.workspace_id', $c->context['workspace_id']);
}
```

Two styles, same value. `@context` threads a key positionally and passes `null` to
the condition when an *optional* key is missing; `$c->context` hands every condition
the whole bag to read however it likes. Pick whichever makes your rules read the
way you want.

## Passing context to a check

Every check API takes a context array. It threads through the facade checks, the
query scopes, and the no-target checks alike:

```php
// Boolean check (context is the third argument):
Warrant::can('update', $document, ['workspace_id' => $id]);

// Row filtering:
Document::query()->userHasAbility('update', context: ['workspace_id' => $id])->paginate();

// Per-row abilities, evaluated in one fixed frame:
Document::query()->selectUserAbilities(context: ['workspace_id' => $id])->get();
```

Whatever you pass is merged *over* [`defaultContext()`](#defaults), with explicit
values winning (a partial merge — you can override just one key).

## Defaults

`defaultContext()` supplies defaults so callers may omit a key — and so
param-less paths ([route middleware](/guides/middleware/), the `userHasAbility`
and `selectUserAbilities` query scopes) get a frame with no `context:` argument:

```php
protected function defaultContext(): array
{
    return ['workspace_id' => app('tenant')->id];
}
```

A default can satisfy a *required* key, so a required key with a default never
throws.

## Required vs. optional

**Keys are optional by default** — a check runs fine with the key absent. Mark a
key required (schema-wide with `#[RequiredContext]`, or per-ability with
`#[Ability(requiredContext: [...])]`) and any check that needs it throws unless
the key is present in the effective context (explicit + defaults):

```text
Schema [...] requires context key(s) [workspace_id]; supply them at the check
or via defaultContext().
```

That loud failure is a feature — a required frame is never silently skipped.

## Missing optional context

An unmarked key is optional and may be absent at check time. When an
optional `@context` key is missing, Warrant passes it to the condition as `null`
and standard SQL logic takes over — a comparison against `null` is `UNKNOWN`.

An `UNKNOWN` condition contributes no access in either direction:

- On a **grant** (`can`), it doesn't grant — no key, no access (fail-closed).
- On a **deny** (`cannot`), the `AND NOT(UNKNOWN)` term drops the row — the veto
  errs toward *blocking*, never lifting (also fail-closed).

So a missing optional key can only ever *remove* access, never silently restore it —
the failure direction is safe. It's still good practice to mark a key that gates a
`cannot` as `required`, so a missing frame fails loudly instead of quietly blocking
rows. **When in doubt, leave it required.**

```text
# If workspace_id is optional and absent, this condition is UNKNOWN and the cannot
# blocks the row (fail-closed). Declare workspace_id required to fail loudly instead.
if outside_workspace(@context workspace_id) they cannot view
```

(A condition is free to treat `null` deliberately — e.g. `whereNull(...)` — if you
want a specific behavior for the absent-key case rather than the default `UNKNOWN`.)
