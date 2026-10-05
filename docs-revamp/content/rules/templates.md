---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Rule templates
description: Name a reusable piece of policy on the schema and expand it with @include.
sidebar:
  order: 8
---

A condition already names a reusable predicate, and a condition may answer with an
expression, so predicates compose without templates:

```php
#[RowCondition]
public function needsApproval(RowConditionContext $c)
{
    return Warrant::parse('is_submitted and not is_approved')->conditionExpression();
}
```

What a condition cannot carry is the denial and its message, because a message only
means anything attached to a `cannot`. So this repeats once per ability, with
nothing to name it:

```warrant
if not is_approved they cannot view because 'This needs approval first.'
if not is_approved they cannot edit because 'This needs approval first.'
```

A template names that whole shape once.

## Declaring one

A public method marked `#[RuleTemplate]`, answering with rule text:

```php
use Warrant\Schema\RuleTemplate;

#[RuleTemplate]
public function requiresApproval(): string
{
    return "if not is_approved they cannot because 'This needs approval first.'";
}
```

The name is the method name snake-cased, `requiresApproval` to
`requires_approval`, exactly as for a condition. Override it with a key:

```php
#[RuleTemplate('approval')]
public function requiresApproval(): string { /* ... */ }
```

Read what a schema declares with `ruleTemplateKeys()`.

A method cannot be both a condition and a template. A condition answers about a
row; a template answers with rule text about none. Both attributes on one method is
rejected when the schema is read.

## The body names no abilities

Clauses in a body take the abilities the `@include` supplies:

```warrant
if not is_approved they cannot because 'This needs approval first.'
```

Three things are rejected in a body, all for the same reason: a clause naming its
own abilities, an [ability block](/rules/ability-blocks/), and a `for <schema>`
header. A body may hold several rules, and may include other templates.

## Expanding one

Inside a block, the header already names the abilities:

```warrant
can they view, edit {
    @include requires_approval
}
```

Anywhere else, the include names them:

```warrant
@include requires_approval for view, edit
```

The `for` list is required outside a block and rejected inside one.

Either way the result is the rules the longhand would have produced, in the place
the `@include` was written:

```warrant
if is_owner they can view
@include requires_approval for view
if is_admin they can view
```

is the same rule set as:

```warrant
if is_owner they can view
if not is_approved they cannot view because 'This needs approval first.'
if is_admin they can view
```

## Arguments

A template may take parameters, passed at the reference the way a condition's are:

```warrant
@include inherited_from(@column parent_id) for view
```

Give the values back to the body through bindings, not by writing them into the
string. `Warrant::ruleTemplate()` pairs the text with them:

```php
use Warrant\Schema\WarrantDenialContext;

#[RuleTemplate]
public function inheritedFrom(string $relation): WarrantRuleTemplate
{
    return Warrant::ruleTemplate(
        'if is_child_of(:relation) they cannot because :why',
        [
            'relation' => $relation,
            'why' => fn (WarrantDenialContext $c) => "No access through {$relation}.",
        ],
    );
}
```

Two reasons for bindings rather than interpolation. Writing a value into the text
is unsafe, since a quote inside a string literal ends it early. And a closure
denial message has no inline form at all, so a binding is the only way one reaches
the language.

A body's placeholders are its own. Every parse gets its own binding state, so a
`:named` body expands cleanly inside a rule set parsed with positional `?` ones.

:::tip[A fixed body stays a string]
`WarrantRuleTemplate` is only needed when the body has placeholders. A method free
to answer with either may declare no return type at all.
:::

## Recursion

A template may include itself. Because arguments may differ at each level, this is
bounded by depth rather than by rejecting a repeated name. A template recurring
with an argument that decreases per level terminates, and rejecting on the name
alone would ban exactly those:

```php
#[RuleTemplate]
public function ancestor(int $depth): string|WarrantRuleTemplate
{
    return $depth <= 0
        ? 'they can'
        : Warrant::ruleTemplate('@include ancestor(:next)', ['next' => $depth - 1]);
}
```

The base case has to be a PHP one. The language has no conditional, so a body
cannot decide for itself when to stop. A recursion that never ends is caught and
reports the chain.

## What sees through a template

| | |
|---|---|
| Compiling a check or filtering a query | expands; a template's rules decide access like any other |
| [Reachability](/concepts/reachability/) | expands; an ability granted only by a template is still reachable |
| [Denial messages](/rules/denial-messages/) | expands; a template's `because` surfaces like any other |
| `Warrant::validate()` | checks the name, the arity, and the abilities named, and does not read the body |
| `WarrantSyntax::toSyntax()` | writes the `@include` back as written, not what it expands to |

Validation stops at the body deliberately. Reading one means calling the method
with concrete arguments, and an argument may be a `@context` reference whose value
arrives per check.

## Errors

| Written | Reported |
|---|---|
| `@include nope for view` | `Schema [...] declares no rule template [nope]` |
| too few arguments | `Rule template [...] requires N argument(s), but the @include supplies M` |
| `@include x for not_an_ability` | `Ability [not_an_ability] is not declared by the schema` |
| `@include x` outside a block | `An @include outside an ability block must name the abilities it applies to` |
| `can they view { @include x for edit }` | `An @include inside an ability block may not name abilities` |
| a body that never stops including | `... exceeded the maximum nesting depth` |

The first three come from `validate()` on rule text alone, so CI catches them with
no user, no row, and no query.
