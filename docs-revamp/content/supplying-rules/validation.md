---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Validation
description: Checking rules before they reach a check, and the guarantee that makes it worth doing.
sidebar:
  order: 5
---

Rules stored as data can be wrong. A condition renamed on the schema, an ability
that never existed, a typo in a stored string. Validation catches those without a
user, a row, or a query.

```php
Warrant::validate(Warrant::parse($storedRule)->scopedTo('documents'));
Warrant::validate($setA, $setB, [$setC, $setD]);
```

It throws on the first unknown ability or condition:

```text
Ability [aprove] is not declared by the schema.
Condition [is_mne] is not declared by the schema.
Condition [in_team] requires at least 1 argument(s), but the rule supplied 0.
```

Each set is checked against the schema registered for its own key. A denial
message after `they can` never gets this far: `because` may only follow
`they cannot`, so the parser rejects it.

## Two places to run it

**On write**, so an administrator gets the error while they are still looking at
the form:

```php
public function store(Request $request)
{
    $ruleText = $request->string('rules');

    try {
        Warrant::validate(Warrant::parse($ruleText)->scopedTo($request->string('schema_key')));
    } catch (WarrantSyntaxException | InvalidArgumentException $e) {
        return back()->withErrors(['rules' => $e->getMessage()]);
    }

    RoleRule::create([...]);
}
```

**In CI**, over everything you have stored, so a schema change that orphans a rule
fails the build rather than a request:

```php
it('every stored rule still compiles', function () {
    $broken = [];

    foreach (RoleRule::all() as $row) {
        try {
            Warrant::validate(Warrant::parse($row->rules)->scopedTo($row->schema_key));
        } catch (Throwable $e) {
            $broken[] = "{$row->role}/{$row->schema_key}: {$e->getMessage()}";
        }
    }

    expect($broken)->toBe([]);
});
```

That turns "a typo in a stored rule silently grants or denies" into a failing test.

## What validation checks

Syntax is checked earlier, at parse time, and throws eagerly with a caret. What
`Warrant::validate()` adds is names, against the registered schema:

- every ability named by a `can` or `cannot` is declared;
- every condition named is declared, with enough arguments;
- every cross-schema target is registered, and for `can(...)` declares the ability;
- a row-bound handle's target has rows, and supplies enough row-key arguments;
- an `as` alias has a row to name;
- a `@column` reference names a frame in scope;
- an `@include` names a declared template with the right arity, and its abilities
  exist.

It checks the rule set twice: as written, and again once
[expanded](/sql/rule-to-query/#before-compiling-expansion), with every template's body and every
[derived condition](/schemas/conditions-beyond-sql/)'s expression in place. So a
mistake inside a template body or a derived condition is reported like one written
in the rule. Expansion needs no user, row or check context, which is what lets
validation read them at all.

Context keys need no declaration to be referenced, so there is no unknown-context-key
error.

## What it deliberately does not check

**Anything that depends on a value.** Whether a `@context` key turned out to be
present, whether a frame is in scope for *this* compile. Those are compile-time
facts.

## The guarantee that makes this worth trusting

Validation is not the only thing standing between a bad rule and bad SQL. The
compiler makes every check the validator makes, independently.

That matters because rules reach the compiler without ever passing through the
validator: one built with the [fluent builder](/rules/builder/), a set assembled
programmatically and never passed to `validate()`. If a check lived only in the validator, every one of those paths
would compile a rule the language forbids, and the mistake would surface as wrong
SQL or wrong access rather than as an error.

So the rule is one-directional:

**Every rejection the validator makes, the compiler makes too.** Nothing is banned
in the validator and allowed in the compiler.

**The reverse is expected.** The compiler legitimately rejects things the validator
cannot see, because it knows values the validator does not.

What that buys you as a user: validation is a way to hear about a mistake
*earlier*, and passing it is never the only thing keeping a rule honest. See
[the validator is never stricter than the
compiler](/sql/validator-compiler-invariant/).

## Validation happens automatically too

A resolved rule set is validated once per request when the guard resolves it, not
once per check. So a stored typo does surface on the first check that touches that
schema, with the same message. Running `Warrant::validate()` yourself is about hearing it
somewhere better than a production request.
