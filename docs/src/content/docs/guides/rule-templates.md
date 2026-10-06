---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Rule templates
description: Name a reusable piece of policy on the schema and expand it into rules with @include.
sidebar:
  order: 3.6
---

A **rule template** is a named, reusable body of rules declared on the schema and
expanded into a rule set by an `@include`. The abilities it applies to come from
the reference, not the template, so one template serves as many abilities as you
point it at.

## What templates are for

A [condition](/guides/conditions/) already names a reusable predicate, and a
[derived condition](/guides/conditions/#derived-conditions) composes predicates
without templates:

```php
#[DerivedCondition]
public function needsApproval(): string
{
    return 'is_submitted and not is_approved';
}
```

What a condition cannot carry is the **denial and its message**, because a
message is only meaningful attached to a `cannot`. So this repeats, once per
ability, with nothing to name it:

```text
if not is_approved they cannot view because 'This needs approval first.'
if not is_approved they cannot edit because 'This needs approval first.'
```

A template names that whole shape once.

## Declaring a template

Mark a public method `#[RuleTemplate]`. It answers with the body as rule text:

```php
use Warrant\Schema\RuleTemplate;

#[RuleTemplate]
public function requiresApproval(): string
{
    return "if not is_approved they cannot because 'This needs approval first.'";
}
```

The name a rule uses is the method name snake-cased — `requiresApproval` →
`requires_approval` — exactly as for a condition. Override it by passing a key:

```php
#[RuleTemplate('approval')]
public function requiresApproval(): string { /* ... */ }
```

Read what a schema declares with `ruleTemplateKeys()`.

:::note[A template is not a condition]
One method cannot be both. A condition answers with SQL or an expression about a
row; a template answers with rule text about none. A method wearing both
attributes is rejected when the schema is read.
:::

## The body is generic

Clauses in a template body name no abilities — they take the ones the `@include`
supplies:

```text
if not is_approved they cannot because 'This needs approval first.'
```

Three things follow from that, and all three are rejected:

- a clause naming its own abilities (`they can view`);
- an [ability block](/guides/rule-language/#grouping-rules-by-ability), which
  would be a second answer to a question the reference settled;
- a `for <schema>` header — a template belongs to the schema that declares it.

A body **may** hold several rules, and may include other templates.

## Expanding a template

Inside an ability block, the header already names the abilities:

```text
can they view, edit {
    @include requires_approval
}
```

Anywhere else, the include names them itself:

```text
@include requires_approval for view, edit
```

The `for` list is required outside a block and rejected inside one, for the same
reason a clause inside a block may not name abilities: the header is the single
place the ability is said.

Either way the result is the rules the longhand would have produced, **in the
place the `@include` was written**:

```text
if is_owner they can view
@include requires_approval for view
if is_admin they can view
```

is the same rule set as:

```text
if is_owner they can view
if not is_approved they cannot view because 'This needs approval first.'
if is_admin they can view
```

## Arguments

A template may take parameters. Pass them at the reference, as you would to a
condition — literals, `:name` / `?` bindings, `@context` and `@column` all work:

```text
@include inherited_from(@column parent_id) for view
```

Give the values back to the body through **bindings**, not by writing them into
the string. Parse the body with them and return the result:

```php
#[RuleTemplate]
public function inheritedFrom(string $relation): WarrantSyntax
{
    return Warrant::parse(
        'if is_child_of(:relation) they cannot because :why',
        [
            'relation' => $relation,
            'why' => fn (WarrantDenialContext $c) => "No access through {$relation}.",
        ],
    );
}
```

Two reasons bindings rather than interpolation. Writing a value into the text is
unsafe — a quote inside a string literal ends it early — and a closure denial
message has no inline form at all, so a binding is the only way it reaches the
DSL.

A body's placeholders are its own: every parse gets its own binding state, so a
`:named` body expands cleanly inside a rule set parsed with positional `?` ones.

:::tip[Return a plain string when there is nothing to fill]
Parsing is only needed for a body with placeholders. A fixed body stays a bare
string, and a method free to answer with either may declare no return type at
all.
:::

## What a template may answer with

A template answers in whichever form it has its body, much as a
[rule provider](/guides/providers/) does:

| Answer | Example |
|---|---|
| Rule text | `'if is_owner they can'` |
| A `WarrantSyntax` | `Warrant::parse('if is_child_of(:rel) they can', ['rel' => $rel])` |
| A rule that names no abilities | `Warrant::rule()->if('is_owner')->theyCan()->toRule()` |
| An `@include` that names no abilities | `new IncludeInvocationNode('requires_approval')` |
| An iterable of any of these, nested | `['if is_owner they can', [$include]]` |

Every rule and include in the answer must be generic. The `@include` that expands
the template names the abilities they take, so a template answering with one that
names its own, with an ability block, or with a rule set is rejected when it is
expanded, naming the template. An empty answer — `''` or `[]` — expands to no
rules.

`Warrant::parse()` reads a template body like any other rule text. Rules with no
`for` header either all name their abilities or all leave them off: the first clause, `@include` or
ability block decides, and one that disagrees is a syntax error at its position.
A rule set has to name them, so generic text returned from a provider is rejected
when it is placed in a rule set.

## Recursion

A template may include itself. Because the arguments may differ at each level,
this is bounded by **depth** rather than by rejecting a repeated name — a
template that recurs with an argument that decreases per level terminates, and
rejecting on the name alone would ban exactly those:

```php
#[RuleTemplate]
public function ancestor(int $depth): string|WarrantSyntax
{
    return $depth <= 0
        ? 'they can'
        : Warrant::parse('@include ancestor(:next)', ['next' => $depth - 1]);
}
```

The base case has to be a PHP one, as above: the DSL has no conditional, so a
body cannot decide for itself when to stop.

A recursion that never ends is stopped at a depth of 64 and reports the chain of
templates. Includes and [derived conditions](/guides/conditions/#derived-conditions)
share that budget, so a chain mixing the two is reported as one.

## What sees through a template

| | |
|---|---|
| Compiling a check or filtering a query | Expands — a template's rules decide access like any other |
| [Reachability](/guides/reachability/) | Expands — an ability granted only by a template is still reachable |
| [Denial messages](/guides/denial-messages/) | Expands — a template's `because` surfaces like any other |
| `validate()` | Expands — a mistake inside a body is reported like one written in the rule |
| `toSyntax()` | Renders the `@include` back out rather than what it expands to |

Expansion happens before anything compiles, once per rule set, and needs no user,
row or check context: a `@context` or `@column` argument reaches the template
method as the reference, to be passed on through a binding. That is what lets
validation read a body at all.

## Errors

| Written | Reported |
|---|---|
| `@include nope for view` | `Schema [...] declares no rule template [nope]` |
| Too few arguments | `Rule template [...] requires N argument(s), but the @include supplies M` |
| `@include x for not_an_ability` | `Ability [not_an_ability] is not declared by the schema` |
| `for docs { @include x }` | `An @include outside an ability block must name the abilities it applies to` |
| `@include x` in a provider's rules with no `for` header | `... the rule set for [docs] holds one that names none`, when it is placed in a rule set |
| A template answering with a rule that names abilities | `Rule template [...] answered with a rule that names abilities` |
| `can they view { @include x for edit }` | `An @include inside an ability block may not name abilities` |
| A body that never stops including | `Expansion exceeded the maximum nesting depth of 64`, followed by the chain |
| A mistake inside a body | Reported as it would be in the rule, e.g. `Condition [x] is not declared by the schema` |

All of these are reported by `validate()`, so CI catches them without a user, a
row or a query.
