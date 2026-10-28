---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Rule templates
description: Name a reusable piece of policy on the schema and expand it with @include.
sidebar:
  order: 9
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
use Warrant\Schema\RuleTemplates\RuleTemplate;

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

Read what a schema declares with `ruleTemplates()`.

A method cannot be both a condition and a template. A condition answers about a
row; a template answers with rule text about none. Both attributes on one method is
rejected when the schema is read.

## The body names no abilities

Can/cannot clauses in a body take the abilities the `@include` supplies:

```warrant
if not is_approved they cannot because 'This needs approval first.'
```

Three things are rejected in a body, all for the same reason: a can/cannot clause naming its
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

The `for` list is required in a schema's own rules and its rule provider's, and
rejected inside a block. A template's body leaves it off. Text that nothing has
placed yet, such as a `.warrant` file, may leave it off, under a `for` header or
not, but only if every other can/cannot clause and include in the text leaves its
abilities off too.

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
string. A body returned as a string may write each parameter as a binding, by its
name as declared or in snake case:

```php
#[RuleTemplate]
public function inheritedFrom(string $parentRelation): string
{
    return "if is_child_of(:parent_relation) they cannot because 'No access through the parent.'";
}
```

`:parentRelation` reads the same. A parameter the include left off takes its
default, and a variadic one is the list of every argument from there on. Each
string in an iterable the template answers with is bound the same way. A binding
that names no parameter is rejected when the template is expanded.

Only a string is bound this way. A body that binds a value of its own, such as a
closure denial message, is parsed with its bindings and returned, and gives exactly
the bindings it was parsed with:

```php
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\Schema\Denials\WarrantDenialContext;

#[RuleTemplate]
public function inheritedFrom(string $relation): WarrantSyntax
{
    return Warrant::parse(warrant(
        'if is_child_of(:relation) they cannot because :why',
        [
            'relation' => $relation,
            'why' => fn (WarrantDenialContext $c) => "No access through {$relation}.",
        ],
    ));
}
```

Two reasons for bindings rather than interpolation. Writing a value into the text
is unsafe, since a quote inside a string literal ends it early. And a closure
denial message has no inline form at all, so a binding is the only way one reaches
the language.

A body's placeholders are its own. Each binding takes its value from the text it
was written in, so a body's `:team` and a `:team` in the rule set that includes
it never meet.

:::tip[A body stays a string]
Parsing is only needed when the body binds a value that is not a parameter. A
method free to answer with either may declare no return type at all.
:::

## Knowing the abilities

A body names no abilities, but it may need to know them, to say which one a denial
is about, say. Take a `RuleTemplateContext` as the first parameter and it holds the
abilities the `@include` supplies, from its `for` list, the block around it, or the
`@include` that expanded the template it sits in:

```php
use Warrant\Schema\RuleTemplates\RuleTemplateContext;

#[RuleTemplate]
public function frozen(RuleTemplateContext $context): WarrantSyntax
{
    return Warrant::parse(warrant('if is_frozen they cannot because :why', [
        'why' => 'This document is frozen: no '.implode(' or ', $context->abilities).'.',
    ]));
}
```

`@include frozen for edit, delete` calls it once, with `['edit', 'delete']`, and
its rules take both abilities.

The context is not a DSL argument, so it is not passed at the `@include`: arguments
written there go to the parameters after it, and only those count towards the
required arguments. Nor is it a binding: a string body's `:context` names no
parameter.

### One ability at a time

To write the body for one ability at a time, declare the template
`callOncePerAbility`. It is called once for each ability the `@include` supplies,
each call's rules taking that ability alone, and `ability()` returns which one:

```php
#[RuleTemplate(callOncePerAbility: true)]
public function frozen(RuleTemplateContext $context): WarrantSyntax
{
    return Warrant::parse(warrant('if is_frozen they cannot because :why', [
        'why' => "You cannot {$context->ability()} a frozen document.",
    ]));
}
```

`@include frozen for edit, delete` now expands to two rules, one for `edit` and one
for `delete`, each with its own message. An `@include` inside the body takes the
one ability too.

`ability()` is refused to a template not declared `callOncePerAbility`, even when
its `@include` happens to name a single ability. Such a template is called once for
all of them, and would break the day a second is added. A template declared
`callOncePerAbility` must take a `RuleTemplateContext`, since without one every call
answers the same.

The wildcard is expanded before the template is called: `$abilities` lists every
ability the schema declares, and `@include frozen for *` calls a per-ability
template once for each of them.

## What a template may answer with

A template answers in whichever form it has its body, much as a
[rule provider](/supplying-rules/provider/) does:

| Answer | Example |
|---|---|
| rule text, its bindings taking the template's parameters | `'if is_child_of(:relation) they can'` |
| a `WarrantSyntax` | `Warrant::parse(warrant('if is_child_of(:rel) they can', ['rel' => $rel]))` |
| a rule that names no abilities | `Build::rule()->if('is_owner')->theyCan()` |
| headless entries, rules and `@include`s naming no abilities | `Build::ruleSet(fn ($rule) => $rule->include('requires_approval'))` |
| an iterable of any of these, nested | `['if is_owner they can', [$include]]` |

Every rule and include in the answer must name no abilities, because the `@include`
that expands the template names them. A template answering with one that names its
own, or with an ability block, is rejected when it is expanded, naming the
template. An empty answer, `''` or `[]`, expands to no rules.

Rule text may put the body under a `for` header naming the template's own schema.
The header says whose conditions and templates the body names, which helps an
editor when the body lives in a file of its own; it says nothing about abilities,
so the rules under it still leave theirs off. A header naming another schema is
rejected. A global schema's template takes no header: its names are looked up in
the global schemas, and a global has no key for a header to name.

```warrant
for documents {
    if is_owner they can
}
```

`Warrant::parse()` reads a body like any other rule text. Its rules either all
name their abilities or all leave them off: the first can/cannot clause,
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
        : Warrant::parse(warrant('@include ancestor(:next)', ['next' => $depth - 1]));
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
| `validate()` | does not expand; it checks the `@include` as written, and the body is checked when a guard expands it |
| `WarrantSyntax::toSyntax()` | writes the `@include` back as written, not what it expands to |

Expansion happens before anything compiles, once per rule set, and needs no user,
row or check context (the abilities a template is told are written in the rule
set): a `@context` or `@column` argument reaches the template method as the
reference, to be passed on through a binding. That is what lets a guard expand its
rules once and use them for every check.

## Errors

| Written | Reported |
|---|---|
| `@include nope for view` | `Rule template [nope], named by an @include, is declared by neither schema [...] nor any global schema.` |
| too few arguments | `Rule template [...] requires N argument(s), but the @include supplies M` |
| too many arguments | `Rule template [...] takes at most N argument(s), but the @include supplies M` |
| `@include x for not_an_ability` | `Ability [not_an_ability] is not declared by the schema` |
| `@include x` in a schema's or provider's rules, under a `for` header or not | `... the rules for [docs] hold one that names none` |
| a template's body under a `for` header naming another schema | `` Rule template [...] answered under a `for` header naming schema [...]; it belongs to schema [...] `` |
| `if a they can view  @include x` | `This @include names none, but the rules before it name theirs` |
| a template answering with a rule that names abilities | `Rule template [...] answered with a rule that names abilities` |
| `#[RuleTemplate(callOncePerAbility: true)]` on a method taking no `RuleTemplateContext` | `Rule template [...] is declared callOncePerAbility, so it must accept a [...RuleTemplateContext] as its first parameter`, when the schema is read |
| `$context->ability()` in a template not declared `callOncePerAbility` | `RuleTemplateContext::ability() is only for a rule template declared #[RuleTemplate(callOncePerAbility: true)]` |
| `can they view { @include x for edit }` | `An @include inside an ability block may not name abilities` |
| a body that never stops including | `Expansion exceeded the maximum nesting depth of 64`, followed by the chain |
| a mistake inside a body | reported as it would be in the rule, e.g. `Condition [x] is declared by neither schema [...] nor any global schema.` |

The ones about an `@include` as written come from `validate()`; the ones about
what a template answers with come when a guard expands the rules. A test that runs
a check catches both, with no row and no query.
