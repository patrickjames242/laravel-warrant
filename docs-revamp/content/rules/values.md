---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Passing values to conditions
description: Inline literals, named bindings, positional bindings, and the rules each obeys.
sidebar:
  order: 4
---

A condition may take arguments. There are three ways to supply them before
compilation, plus [`@context`](/concepts/context/in-rules/), which is resolved
later when the check runs.

## Inline literals

Written straight into the rule. Strings, ints, floats, bools, and null:

```warrant
if in_team('sales') they can view
if seen_recently(30, true) they can view
if has_rating(4.5) they can promote
if assigned_to(null) they can claim
```

Strings take single or double quotes, so pick whichever avoids escaping:

```warrant
if named("can't touch this") they can view
if named('she said "no"') they can view
```

The closing quote must match the opener. Escape a quote or backslash with `\'`,
`\"`, `\\`.

Arrays and objects cannot be written inline. Pass those through a binding.

## Named bindings

`:name` placeholders filled from an array. The name is what matters, so one value
may be used any number of times, anywhere in the string, and array order is
irrelevant:

```php
WarrantRuleSet::fromSyntax('
    if is_specific_user(:uid) they can view
    if delegated_to(:uid)     they can approve
', 'documents', ['uid' => $currentUserId]);
```

## Positional bindings

`?` placeholders filled left to right across the entire string:

```php
WarrantRuleSet::fromSyntax(
    'if in_team(?, ?) they can view',
    'documents',
    ['sales', 'eng'],
);
```

## The rules bindings obey

Enforced at parse time, and all three catch real mistakes:

**A binding value may be any PHP value.** Strings, ints, arrays, objects,
anything. Only inline literals are restricted to scalars. Your condition receives
it verbatim:

```php
WarrantRuleSet::fromSyntax('if in_teams(:teams) they can view', 'documents', [
    'teams' => ['sales', 'eng', 'ops'],
]);
```

```php
#[RowCondition]
public function inTeams(RowConditionContext $c, array $teams): Builder
{
    return $c->query->whereIn($c->row('team_id'), $teams);
}
```

**Named and positional cannot be mixed** in one parse.

**Every placeholder needs a value, and every value must be used.** A missing
binding, an unused one, or a positional count mismatch is an error:

```text
No binding provided for ":uid".
Binding(s) provided but never used: region.
More positional placeholders (?) than bindings provided.
```

## How arguments reach the method

The context object is always the first parameter. Then parameter 2 takes argument
0, parameter 3 takes argument 1, and so on:

```php
#[RowCondition]
public function inTeam(RowConditionContext $c, string $team): Builder
{
    // in_team('sales')  ->  $team === 'sales'
    return $c->query->where($c->row('team_id'), $team);
}
```

A variadic tail collects a list argument:

```php
#[RowCondition]
public function inAnyTeam(RowConditionContext $c, string ...$teams): Builder
{
    // in_any_team('sales', 'eng')  ->  $teams === ['sales', 'eng']
    return $c->query->whereIn($c->row('team_id'), $teams);
}
```

A parameter with a default is optional, so the rule may omit that argument.
Supplying fewer than the required parameters is rejected during validation.
Supplying more is fine: the extras are ignored by the call and stay reachable on
`$c->arguments`, which is the full positional array.

Type a parameter only as loosely as its values allow. An argument that may be an
absent `@context` key has to be `mixed` or nullable.

## Raw SQL

When a value has to be an expression the language has no syntax for, `@sql` splices
one in verbatim:

```warrant
if pay_period_matches(@sql "select pay_period_id from settings limit 1") they can view
```

The body is wrapped in one pair of parentheses and handed to the condition as an
`Illuminate\Database\Query\Expression`, which is exactly what `DB::raw('(' . $sql .
')')` produces. The parentheses are always added, so a bare `select ...` is valid
as a scalar subquery in a comparison.

The body may also come from a binding, which is how you keep long SQL out of the
rule text:

```php
WarrantRuleSet::fromSyntax('if matches(@sql :q) they can view', 'documents', [
    'q' => 'select pay_period_id from settings limit 1',
]);
```

Note the one difference from `@context` and `@column`: a binding used as an `@sql`
body **is** consumed and does count toward the every-binding-used rule. The
string-literal form consumes nothing.

:::danger[No escaping, no quoting, no binding]
`@sql` splices its body into the query untouched. Never build one from untrusted
input; treat it exactly like `DB::raw()`. It is also your responsibility that the
tables it names are in scope in the surrounding query and that the fragment is
valid for your connection.
:::
