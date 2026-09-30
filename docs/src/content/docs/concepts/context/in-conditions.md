---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Reading context in conditions
description: The ambient $c->context bag, and when to prefer it over @context.
sidebar:
  order: 3
---

Every condition receives the full effective context on `$c->context`, whether or
not the rule mentioned a key. So a condition tied to a frame can read it directly
and let the rule stay quiet about it:

```php
#[RowCondition]
public function inCurrentWorkspace(RowConditionContext $c): Builder
{
    return $c->query->where($c->row('workspace_id'), $c->context['workspace_id']);
}
```

```warrant
if in_current_workspace they can view
```

Compare with the `@context` form, which says the same thing with the key visible in
the rule:

```warrant
if in_workspace(@context workspace_id) they can view
```

## Which to use

Use `@context` when the key is part of the policy. Someone reading the rule should
see that this grant is scoped to a workspace, and a different rule might scope to a
different key.

Use `$c->context` when the key is part of the schema. Every rule on this resource
is workspace-scoped, always, and repeating the key in every rule is noise.

They are the same value either way.

## The one behavioural difference

An absent optional key reaches a `@context` parameter as `null`, and standard SQL
logic takes over from there, which is fail-closed.

A condition reading `$c->context` decides for itself what absent means, and has to
decide something:

```php
#[RowCondition]
public function inCurrentWorkspace(RowConditionContext $c): ?Builder
{
    $workspace = $c->context['workspace_id'] ?? null;

    if ($workspace === null) {
        return null;   // answer unknown, which grants nothing
    }

    return $c->query->where($c->row('workspace_id'), $workspace);
}
```

Returning `null` there is usually right. Returning `false` would be wrong, because
`not false` is `true`, so a missing key could lift a denial elsewhere. See
[true, false, and unknown](/concepts/three-truth-values/).

:::caution[Answering null means touching nothing]
A condition that answers `null` must leave `$c->query` alone. PHP returns `null`
from a method with no `return`, so a condition that constrained the query and then
fell off the end looks identical to a deliberate unknown. Warrant refuses the
combination rather than guessing:

```text
Condition [x] on schema [Y] returned null, answering unknown, but also added a
where clause; return the builder it constrained, or answer unknown without
constraining it.
```

Nearly always, that means a missing `return`.
:::
