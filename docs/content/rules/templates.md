---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Rule templates
description: Name a reusable piece of policy on the schema and expand it with @include.
sidebar:
  order: 8
---

A condition already names a reusable predicate, and a
[derived condition](/schemas/conditions-beyond-sql/) composes predicates without
templates:

```php
#[DerivedCondition]
public function needsApproval(): string
{
    return 'is_submitted and not is_approved';
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

The `for` list is required in a rule set and rejected inside a block. Rules with
no `for` header may leave it off, as a template's body does, but only if every
other clause and include in the text leaves its abilities off too.

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
string. Parse the body with them and return the result:

```php
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\Schema\WarrantDenialContext;

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

Two reasons for bindings rather than interpolation. Writing a value into the text
is unsafe, since a quote inside a string literal ends it early. And a closure
denial message has no inline form at all, so a binding is the only way one reaches
the language.

A body's placeholders are its own. Every parse gets its own binding state, so a
`:named` body expands cleanly inside a rule set parsed with positional `?` ones.

:::tip[A fixed body stays a string]
Parsing is only needed when the body has placeholders. A method free to answer
with either may declare no return type at all.
:::

## What a template may answer with

A template answers in whichever form it has its body, much as a
[rule provider](/supplying-rules/provider/) does:

| Answer | Example |
|---|---|
| rule text | `'if is_owner they can'` |
| a `WarrantSyntax` | `Warrant::parse('if is_child_of(:rel) they can', ['rel' => $rel])` |
| a rule that names no abilities | `Warrant::rule()->if('is_owner')->theyCan()->toRule()` |
| an `@include` that names no abilities | `new IncludeInvocationNode('requires_approval')` |
| an iterable of any of these, nested | `['if is_owner they can', [$include]]` |

Every rule and include in the answer must name no abilities, because the `@include`
that expands the template names them. A template answering with one that names its
own, with an ability block, or with a rule set is rejected when it is expanded,
naming the template. An empty answer, `''` or `[]`, expands to no rules.

`Warrant::parse()` reads a body like any other rule text. Rules with no `for`
header either all name their abilities or all leave them off: the first clause,
`@include` or ability block decides, and one that disagrees is a syntax error at
its position.

## Recursion

A template may include itself. Because arguments may differ at each level, this is
bounded by depth rather than by rejecting a repeated name. A template recurring
with an argument that decreases per level terminates, and rejecting on the name
alone would ban exactly those:

```php
#[RuleTemplate]
public function ancestor(int $depth): string|WarrantSyntax
{
    return $depth <= 0
        ? 'they can'
        : Warrant::parse('@include ancestor(:next)', ['next' => $depth - 1]);
}
```

The base case has to be a PHP one. The language has no conditional, so a body
cannot decide for itself when to stop. A recursion that never ends is stopped at a
depth of 64 and reports the chain. Includes and
[derived conditions](/schemas/conditions-beyond-sql/) share that budget, so a chain
mixing the two is reported as one.

## What sees through a template

| | |
|---|---|
| Compiling a check or filtering a query | expands; a template's rules decide access like any other |
| [Reachability](/concepts/reachability/) | expands; an ability granted only by a template is still reachable |
| [Denial messages](/rules/denial-messages/) | expands; a template's `because` surfaces like any other |
| `Warrant::validate()` | expands; a mistake inside a body is reported like one written in the rule |
| `WarrantSyntax::toSyntax()` | writes the `@include` back as written, not what it expands to |

Expansion happens before anything compiles, once per rule set, and needs no user,
row or check context: a `@context` or `@column` argument reaches the template
method as the reference, to be passed on through a binding. That is what lets
validation read a body at all.

## Errors

| Written | Reported |
|---|---|
| `@include nope for view` | `Schema [...] declares no rule template [nope]` |
| too few arguments | `Rule template [...] requires N argument(s), but the @include supplies M` |
| `@include x for not_an_ability` | `Ability [not_an_ability] is not declared by the schema` |
| `for docs { @include x }` | `An @include outside an ability block must name the abilities it applies to` |
| `@include x` in a provider's rules with no `for` header | `... the rule set for [docs] holds one that names none`, when it is placed in a rule set |
| `if a they can view  @include x` | `This @include names none, but the rules before it name theirs` |
| a template answering with a rule that names abilities | `Rule template [...] answered with a rule that names abilities` |
| `can they view { @include x for edit }` | `An @include inside an ability block may not name abilities` |
| a body that never stops including | `Expansion exceeded the maximum nesting depth of 64`, followed by the chain |
| a mistake inside a body | reported as it would be in the rule, e.g. `Condition [x] is not declared by the schema` |

All of these come from `validate()`, so CI catches them with no user, no row, and
no query.
