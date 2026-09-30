---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Memoization limits
description: What is cached, what is not, and where the boundary surprises people.
sidebar:
  order: 2
---

Warrant memoizes two things, both for the life of one request.

**A guard per user.** `Warrant::guard($user)` twice returns the same object.

**A resolved rule set per guard.** So `resolve()` runs at most once per user and
schema, and the set is validated once. Nothing else is cached.

## What is not memoized

**Condition SQL.** Each condition method runs per compile. Twenty checks against
the same schema compile twenty predicates, and each one calls the condition methods
again.

That matters if a condition does real work in PHP:

```php
#[RowCondition]
public function inMyTeam(RowConditionContext $c): Builder
{
    // Runs on every compile. A query each time.
    return $c->query->whereIn($c->row('team_id'), $c->user->teams()->pluck('id'));
}
```

Memoize it yourself, on the user or in a request-scoped service:

```php
return $c->query->whereIn($c->row('team_id'), $c->user->teamIds());
```

```php
public function teamIds(): array
{
    return $this->teamIdCache ??= $this->teams()->pluck('id')->all();
}
```

**Check results.** Asking the same question twice runs it twice.

**Anything across requests.** The memo has no store behind it and no TTL.

**Anything keyed by context.** The memo is per user and schema. A different
`context:` array does not invalidate it, and does not need to, because context is
applied at compile time rather than at resolve time.

## The tenant trap

The memo is keyed by user identity, not by tenant. In a request that switches
tenant, the second tenant answers from the first tenant's rules:

```php
$tenancy->setCurrent('tenant-a');
Warrant::can('view', $docA, user: $user);     // resolves for tenant-a

$tenancy->setCurrent('tenant-b');
Warrant::can('view', $docB, user: $user);     // still tenant-a's rules
```

Flush in between:

```php
$tenancy->setCurrent('tenant-b');
Warrant::flush($user);
```

If one request can switch tenant at all, make the flush part of whatever switches
it rather than remembering it at each call site.

## The impersonation trap

Same shape. Impersonation usually changes which user is checked, which is fine,
since the memo is per user. It is when you change a *flag* on the same user that it
bites:

```php
session()->put('impersonating', $other->id);

Warrant::flush($staff);
```

## Tests

A test is one long-lived request, so the memo lives for the whole test. Changing
rules, roles, or the resolver mid-test needs a flush. See
[test helpers](/testing/helpers/).

## Adding your own layer

If your resolver reads from storage, cache there, where you control invalidation:

```php
public function resolve(RuleResolutionContext $context): WarrantRuleSet
{
    $text = Cache::remember(
        "warrant.rules.{$context->user->role_id}.{$context->schemaKey}",
        now()->addMinutes(10),
        fn () => $this->fetchRuleText($context),
    );

    return WarrantRuleSet::fromSyntax($text, $context->schemaKey);
}
```

Warrant's memo then sits in front of yours, so a request checking twenty documents
hits neither.

Cache the rule *text* or the built set. Do not cache check results: the predicate
depends on row state that changes, and a stale allow is a security bug where a
stale rule set is merely out of date.

Invalidate on write:

```php
$roleRule->update(['rules' => $text]);

Cache::forget("warrant.rules.{$roleRule->role_id}.{$roleRule->schema_key}");
Warrant::flush();
```
