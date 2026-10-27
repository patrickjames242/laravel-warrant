---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Passing values to conditions
description: Inline literals, named bindings, abilities from a binding, and the rules each obeys.
sidebar:
  order: 5
---

A condition may take arguments. There are two ways to supply them before
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
WarrantSyntax::parse(warrant('
    if is_specific_user(:uid) they can view
    if delegated_to(:uid)     they can approve
', ['uid' => $currentUserId]))->validate()->scopedTo('documents');
```

## The rules bindings obey

Enforced when the rules are taken out of the text, and all three catch real
mistakes:

**A binding value may be any PHP value.** Strings, ints, arrays, objects,
anything. Only inline literals are restricted to scalars. Your condition receives
it verbatim:

```php
WarrantSyntax::parse(warrant('if in_teams(:teams) they can view', [
    'teams' => ['sales', 'eng', 'ops'],
]))->validate()->rule();
```

```php
#[RowCondition]
public function inTeams(RowConditionContext $c, array $teams): Builder
{
    return $c->query->whereIn($c->column('team_id'), $teams);
}
```

**Every placeholder needs a value, and every value must be used.** A missing
binding or an unused one is an error:

```text
No binding provided for ":uid".
Binding(s) provided but never used: region.
```

## Abilities from a binding

A binding may also stand wherever an ability is written: in a `they can` or
`they cannot` list, an ability block header, an `@include`'s `for` list, and a
`can` reference. Its value is an ability name, `'*'`, or a list of them, and a
list is spliced into the list where the binding stands:

```php
WarrantSyntax::parse(warrant('
    if is_editor they can view, :editing
    can they :reviewing { if is_reviewer they can }
    @include owned for :owning
    if can(:gate) they can publish
', [
    'editing'   => ['update', 'archive'],
    'reviewing' => 'comment',
    'owning'    => ['view', 'delete'],
    'gate'      => 'approve',
]))->validate()->scopedTo('documents');
```

The first rule grants `view`, `update` and `archive`. Names and bindings mix
freely in one list.

A `can` reference asks about one ability, so its binding's value is one ability
name. A list there is an error.

After [`* except`](/rules/basics/#wildcards) a binding names abilities to leave
out: an ability name or a list of them, but not `'*'`:

```php
warrant('if is_read_only they cannot * except :reading', ['reading' => ['view', 'list']]);
```

In a list the value must name at least one ability, and nothing else:

```text
The binding [:editing] stands in an ability list, so its value is an ability name or a non-empty list of them; got an empty list.
The binding [:gate] stands for the one ability a can(...) reference asks about, so its value is an ability name; got array.
```

An empty list, an array with keys, a nested list and a value that is not a string
are each rejected the same way. A bound ability the schema does not declare is
the same error as a written one: `Ability [%s] is not declared by the schema.`

Each of these needs the values, so an editor, which has none, reports none of
them. It still reads a binding as an ability wherever one may stand.

[Each ability once](/rules/basics/#each-ability-once) is judged on the list as
written, never on what its bindings hold: `they can :editing, view` is fine even
when `:editing` holds `view`, while `they can :editing, :editing` names one
binding twice.

## How arguments reach the method

The context object is always the first parameter. Then parameter 2 takes argument
0, parameter 3 takes argument 1, and so on:

```php
#[RowCondition]
public function inTeam(RowConditionContext $c, string $team): Builder
{
    // in_team('sales')  ->  $team === 'sales'
    return $c->query->where($c->column('team_id'), $team);
}
```

A variadic tail collects a list argument:

```php
#[RowCondition]
public function inAnyTeam(RowConditionContext $c, string ...$teams): Builder
{
    // in_any_team('sales', 'eng')  ->  $teams === ['sales', 'eng']
    return $c->query->whereIn($c->column('team_id'), $teams);
}
```

A parameter with a default is optional, so the rule may omit that argument.
Supplying fewer than the required parameters is rejected during validation, and
so is supplying more than the method has parameters for, unless its last
parameter is variadic, as `inAnyTeam()`'s is. `$c->arguments` holds every argument
in the order written. The same holds for a rule template's parameters and an
`@include`'s arguments.

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
WarrantSyntax::parse(warrant('if matches(@sql :q) they can view', [
    'q' => 'select pay_period_id from settings limit 1',
]))->validate()->rule();
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
