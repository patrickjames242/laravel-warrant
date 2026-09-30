---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: 2. Write a rule
description: One rule in the Warrant language, read piece by piece.
sidebar:
  order: 2
---

A rule is a string. Here is the whole policy for step two:

```warrant
if is_mine they can view, update
```

Read it left to right. `is_mine` is the condition we declared on the schema.
`they` is the user being asked about, the one your resolver was handed. `can view,
update` grants two abilities. So: when a document is mine, I may view and update
it.

Rules are order-independent and whitespace-insensitive, so you can lay one out
however it reads best:

```warrant
if is_mine
they can view, update
```

## Adding a second clause

Suppose locked documents cannot be edited by anyone. That is a `cannot`, and it
goes in its own rule:

```warrant
if is_mine they can view, update

if is_locked they cannot update
```

You will need the matching condition on the schema:

```php
#[RowCondition]
public function isLocked(RowConditionContext $c): Builder
{
    return $c->query->where($c->row('locked'), true);
}
```

A `cannot` always beats a `can`, whatever order the rules are in. The two rules
above grant `update` on my own documents and take it away again on the locked
ones. Nothing later can put it back, which is covered in
[grants, denials, and appeals](/concepts/grants-and-denials/).

## Grouping by ability

When several rules are about one ability, say the ability once with a block:

```warrant
can they update {
    if is_mine they can
    if is_locked they cannot
}
```

That produces exactly the two rules above. Clauses inside a block name no
abilities of their own, because the header already did.

## What a rule is not

A rule is not code that runs. There is no early return, no `else`, no calling a
PHP function halfway through. Every rule compiles into a `WHERE` clause, which is
what lets the same rule answer "can I?" and "which ones?". That constraint is the
whole design, and [it has its own page](/concepts/rules-compile-to-sql/).

Next: [hand the rule to Warrant](/first-rule/resolver/).
