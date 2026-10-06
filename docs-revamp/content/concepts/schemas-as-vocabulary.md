---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Schemas as vocabulary
description: A schema says what a rule is allowed to talk about, and decides nothing itself.
sidebar:
  order: 8
---

It is tempting to read a schema as the policy for a model. It is not. A schema is
the set of words a rule may use, and it makes no access decision on its own.

```php
class DocumentSchema extends WarrantSchema
{
    public const model = Document::class;

    #[Ability] public const VIEW   = 'view';
    #[Ability] public const UPDATE = 'update';

    #[RowCondition]
    public function isMine(RowConditionContext $c): Builder { /* ... */ }

    #[RowCondition]
    public function isLocked(RowConditionContext $c): Builder { /* ... */ }
}
```

Nothing there says who may do what. It says that `view` and `update` are things one
can be granted, and that `is_mine` and `is_locked` are questions one can ask. The
policy lives in rules, which are data, supplied per user.

## Why the split earns its keep

**Rules become storable.** A policy expressed as a string can live in a table, be
edited by an administrator, differ per tenant, and be diffed in a pull request.
Policy expressed as PHP cannot.

**Names can be checked.** Because the vocabulary is closed, a typo is an error
rather than a silent denial:

```warrant
if is_mne they can view
```

```text
Condition [is_mne] is not declared by the schema.
```

You can run that check in CI over every rule you have stored, with no user, no
row, and no database:

```php
Warrant::validate(WarrantSyntax::parse($storedRule)->scopedTo('documents'));
```

**Tooling can read your rules.** A closed vocabulary is what makes completion and
diagnostics possible for `.warrant` files and `WARRANT` heredocs. See
[editor tooling](/editors/overview/).

## What belongs in the vocabulary

A condition should name a question, not an answer. `is_locked` is a good
condition: it is a fact about a row, and different rule sets will want it for
different purposes. `can_be_edited_by_managers` is a bad one, because it has
already made a decision, and the decision belongs in a rule where it can vary per
user.

That distinction is what keeps a schema stable while the policy changes.

## The two things a schema does decide

The split is not absolute, and two escape hatches are deliberate.

[`rules()`](/schemas/schema-policy/) returns the schema's own rules, merged ahead
of whatever the provider returns, for guarantees that must not depend on it. A
suspension lockout belongs here. Without a global provider, they are the schema's
only rules.

[`defaultContext()`](/concepts/context/supplying/) supplies values so callers may
omit them, which is what makes param-less paths such as route middleware and query
scopes work at all.

Both are documented as policy on purpose. Everything else on a schema is
vocabulary.
