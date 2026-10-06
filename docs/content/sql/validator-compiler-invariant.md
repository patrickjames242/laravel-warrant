---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: The validator is never stricter than the compiler
description: A guarantee about where checks live, and why it matters to anyone whose rules skip the parser.
sidebar:
  order: 4
---

Warrant checks a rule twice. Once when the rule set is validated, and again when it
is compiled. The relationship between those two passes is fixed, and it is worth
knowing because it tells you what passing validation does and does not buy.

**Every rejection the validator makes, the compiler makes too.** Nothing is banned
in the validator and allowed in the compiler.

**The reverse is expected.** The compiler legitimately rejects things the validator
cannot see, because it knows values the validator does not.

## Why it has to be that way

The validator reads rule text. Plenty of rules never are rule text.

A rule built with the [fluent builder](/rules/builder/) goes straight to an AST:

```php
WarrantRuleNode::build()
    ->ifCan('view', 'folders', Ref::column('parent_id'))
    ->theyCan('view')
    ->toRule();
```

A [derived condition](/schemas/conditions-beyond-sql/) may build its expression
the same way. `validate()` expands it and reads the result, but only for a rule set
someone validates:

```php
#[DerivedCondition]
public function parentIsOwned(): WarrantConditionBuilder
{
    return WarrantConditionBuilder::build()->ifCheck('is_owner', FolderSchema::class, Ref::column('parent_id'));
}
```

A rule set assembled programmatically from a table of fragments never passes
through `validate()` at all if nobody calls it.

If a check lived only in the validator, every one of those paths would compile a
rule the language is supposed to forbid, and the mistake would surface as wrong SQL
or wrong access rather than as an error. Which is the worst way to learn about it.

## What it looks like in practice

Write a bad handle as text and validation catches it:

```warrant
if can(view for folders) they can view
```

```text
A can(...) reference targets a specific row of schema [folders], but [folders] has
no way to name one; declare `const key` … or drop the row selector.
```

Produce the same mistake from PHP and the compiler catches it, with its own message
for the same thing:

```php
WarrantConditionBuilder::build()->ifCan('view', 'folders', $someKey);
```

The two need not word it identically. Both have to report.

## Where the compiler goes further

These are compiler-only on purpose, because each depends on a value the validator
does not have:

- a `@context` key that turned out to be absent at this check;
- a frame that is not in scope for *this* compile, which depends on how the rule
  was reached;
- a row selector that is a model of the wrong schema, or an object with no meaning
  as a row key;
- a target on a different database connection;
- a cross-schema cycle, or nesting past the depth cap;
- what a condition actually emitted, such as a `join`, nothing at all, or a `null`
  alongside a where clause.

None of those is a gap in validation. They are questions with no answer until a
check runs.

## What this buys you

Validation is a way to hear about a mistake **earlier**, and passing it is never
the only thing keeping a rule honest.

So:

- Run [`validate()`](/supplying-rules/validation/) in CI over stored rules, and on
  write in an admin UI, because catching a typo before a request is strictly better.
- Do not treat it as a security boundary. A rule that skipped validation is still
  judged.
- Do not write a check that lives only in the validator if you are extending
  Warrant. The compiler's own `assertHandleIsWellFormed` is the shape to copy: it
  makes the same checks over a handle that the rule-set validator makes over rule
  text, precisely so that a handle which never passed through the parser is still
  judged.

## An unanswerable question is not a rejection

Worth separating, because the messages look similar from a distance.

A *malformed* handle is rejected: it could never be right, however the check is
made.

An *unanswerable* question folds to [unknown](/sql/unknown/): a row condition with
no row, an absent `@context` key, a `@column` about another frame. Those are
well-formed rules meeting a situation where there is nothing to say, and unknown is
the answer, not an error.
