---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: A rule was rejected
description: Reading a syntax or validation error, and finding the rule text it points at.
sidebar:
  order: 3
---

Warrant fails loudly on a bad rule rather than granting or denying quietly. The
errors arrive at three different moments, and knowing which moment tells you where
to look.

| Moment | What is checked | Exception |
|---|---|---|
| parse | syntax, binding consistency | `WarrantSyntaxException` |
| validate or compile | ability and condition names, arity, handles | `InvalidArgumentException` |
| check | the requested ability exists, required context present | `InvalidArgumentException` |

## Syntax errors

Thrown eagerly, with a line, a column, and a caret, which stays readable even when
the whole set is one line:

```text
Reserved word 'can' cannot be used as a name; expected an ability name. (line 1, column 21)

    if is_self they can can
                        ^
```

The exception carries the source so you can log it:

```php
try {
    WarrantRuleSet::fromSyntax($text, 'documents');
} catch (WarrantSyntaxException $e) {
    logger()->error($e->getMessage(), [
        'source' => $e->source,
        'line'   => $e->sourceLine,
        'column' => $e->sourceColumn,
    ]);
}
```

The ones you will actually hit:

**`Unterminated string literal.`** Usually a quote inside a message. Switch the
delimiter rather than escaping: `"can't"` instead of `'can\'t'`.

**`Expected 'can' or 'cannot' after 'they'.`** A typo, or an ability block clause
that named its own abilities.

**`Expected at least one 'they can ...' or 'they cannot ...' clause.`** An `if` with
nothing attached.

**`Reserved word '%s' cannot be used as a name.`** A condition or ability named
`for`, `with`, `as`, `check`, and so on. It may *contain* one, so rename
`for` to `for_team`.

## Binding errors

Also `WarrantSyntaxException`, and all five are about the same strictness:

```text
Cannot mix named and positional bindings.
No binding provided for ":uid".
More positional placeholders (?) than bindings provided.
2 positional binding(s) were provided but never used.
Binding(s) provided but never used: region.
```

The last one catches a real mistake more often than it looks: a binding left behind
after a rule was edited, which usually means the rule no longer does what its
author thought.

Remember that `@context`, `@column`, and the string-literal form of `@sql` consume
no binding and are exempt from these rules. An `@sql` body written *as* a binding
does consume it.

## Name errors

Thrown when a set is validated or compiled:

```text
Ability [aprove] is not declared by the schema.
Condition [is_mne] is not declared by the schema.
Condition [in_team] requires at least 1 argument(s), but the rule supplied 0.
```

Find the vocabulary:

```php
DocumentSchema::abilityNames();
DocumentSchema::conditionKeys();
DocumentSchema::ruleTemplateKeys();
```

:::note[Two different "unknown ability" messages]
*is not declared by the schema* comes from validating a rule set. A different one
fires at check time when the ability you **request** is not declared:

```text
Ability [destroy] is not defined on schema [App\Warrant\DocumentSchema].
```

Same root cause, different call site. The first points at your rules, the second at
your call.
:::

## Handle errors

```text
A can(...) reference targets a specific row of schema [settings], but [settings] has
no way to name one; declare `const key` … or drop the row selector.

A can(...) reference to schema [shift_days] supplies 1 row-key argument(s), but that
schema's row key requires at least 2.

A can(...) reference to schema [folders] specifies a row target that is null; supply
a row id or a @context reference, or drop the row selector.
```

The last one is deliberate. A `$folder?->id` that came back null fails loudly rather
than quietly widening a question about one row into a question about the schema.

## Finding which stored rule

When rules live in a table, the message names the condition rather than the row.
Find it by validating each one:

```php
foreach (RoleRule::all() as $row) {
    try {
        WarrantRuleSet::fromSyntax($row->rules, $row->schema_key)->validate();
    } catch (Throwable $e) {
        $this->warn("{$row->id} ({$row->role}/{$row->schema_key}): {$e->getMessage()}");
    }
}
```

Keep that as a test, and you find out at build time instead. See
[validation](/supplying-rules/validation/).

## A rejection that only happens sometimes

If a rule validates in CI and fails in production, it is depending on a value:

- a `@context` key present in one path and absent in another;
- a `@column` naming a frame that is in scope at the top of a query and not through
  a hop;
- a row selector that is a model of the wrong schema;
- a target schema on a different database connection.

Those are compiler-only checks by design. See
[the validator is never stricter than the
compiler](/sql/validator-compiler-invariant/).
