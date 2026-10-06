---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Error reference
description: Every exception Warrant throws, when it fires, and the usual fix.
sidebar:
  order: 6
---

Warrant fails loudly. A typo in a stored rule, a missing context key, or a
misconfigured schema throws rather than silently granting or denying.

## When each fires

| Stage | What is checked |
|---|---|
| parse | rule syntax, binding consistency |
| expansion | templates and derived conditions resolve, answer sensibly, and terminate |
| compile or validate | ability and condition names against the schema |
| check | the requested ability exists, required context present, a user is available |
| first resolution | schema registry consistency, condition method signatures |

## Syntax, `WarrantSyntaxException`

Extends `RuntimeException` and carries `$source`, `$offset`, `$sourceLine`, and
`$sourceColumn`.

```text
Reserved word 'can' cannot be used as a name; expected an ability name. (line 1, column 21)

    if is_self they can can
                        ^
```

Representative messages:

- `Unexpected character %s.`
- `Unterminated string literal.`
- `Invalid escape sequence "\%s"; only \', \", and \\ are allowed.`
- `Expected 'context', 'column', 'sql', or 'include' after '@'.`, `Expected a context key after '@context'.`
- `Expected 'can' or 'cannot' after 'they'.`
- `Expected at least one 'they can ...' or 'they cannot ...' clause.`
- `'because' may only follow a 'they cannot ...' clause, not 'they can ...'.`
- `A clause inside an ability block may not name abilities; the block header already names them.`
- `Expected ')' to close the group.`, `Expected ')' to close the condition arguments.`
- `Reserved word '%s' cannot be used as a name; expected %s.`

### The shape of the source

One parse reads every form, so these are about how the pieces sit together:

- `` A `{ ... }` block needs a `for <schema>` header before it. ``
- `` Rules without a `for` header cannot be followed by a `for` block; put them in a block of their own. ``
- `` Multiple rule sets in one source must each be braced, as `for <schema> { ... }`. ``
- `Unexpected token; expected end of input.`, usually a condition expression with
  rules after it.

Asking a parse for a shape it does not hold is a `LogicException`, naming both:

```text
Expected a single rule, but the source holds a rule set for [documents].
```

`scopedTo()` given a schema the `for` header disagrees with is an
`InvalidArgumentException`:

```text
The rule text targets schema [documents] in its `for` header but was scoped to [folders].
```

### Bindings

- `Cannot mix named and positional bindings.`
- `No binding provided for ":%s".`
- `More positional placeholders (?) than bindings provided.`
- `%d positional binding(s) were provided but never used.`
- `Binding(s) provided but never used: %s.`

## Validation, `InvalidArgumentException`

- `Ability [%s] is not declared by the schema.`
- `Condition [%s] is not declared by the schema.`
- `Condition [%s] requires at least %d argument(s), but the rule supplied %d.`

Context keys need no declaration to be referenced, so there is no unknown-context-key
error.

Type guards:

- `fromRules expects WarrantRuleNode or WarrantRuleBuilder instances, got %s.`
- `validate expects RuleSetNode instances, got %s.`
- `A rule set holds rules, ability blocks and includes, got %s.`
- `Every clause and @include outside an ability block names the abilities it applies to; the rule set for [%s] holds one that names none.`

:::note[Two "unknown ability" messages]
*is not declared by the schema* comes from validating a rule set. A different one
fires at check time when the **requested** ability is not declared:
*Ability [%s] is not defined on schema [%s].*
:::

## Conditions and reflection, `InvalidArgumentException`

Thrown the first time a schema's conditions are reflected:

- `Condition method [%s::%s] must not declare duplicate condition attributes.`
- `Condition method [%s::%s] must declare exactly one of #[RowCondition], #[GlobalCondition] and #[DerivedCondition].`
- `Derived condition [%s::%s] takes no context object; its parameters are its DSL arguments alone. ...`: a derived condition written with a `RowConditionContext` or `GlobalConditionContext` parameter.
- `Method [%s::%s] cannot be both a condition and a rule template.`
- `Condition method [%s::%s] must resolve to a non-empty condition key.`
- `Condition method [%s::%s] must accept a [%s] as its first parameter.`
- `Schema [%s] has no rows and does not support targeted checks; use a no-target check instead.`
- `Schema [%s] has rows but no way to name one, so it does not support targeted checks; declare `const key` …`

## Applying a condition

From the resolver:

- `BadMethodCallException`: `Condition [%s] is not defined on schema [%s].`
- `Condition [%s] on schema [%s] requires a target row.`
- `Condition [%s] on schema [%s] requires at least %d argument(s), but the rule supplied %d.`
- `Condition [%s] on schema [%s] returned an expression; a row or global condition answers with the query it constrained, a bool or null. Declare a condition built from other conditions #[DerivedCondition] instead, ...`
- `Condition [%s] on schema [%s] is a derived condition, which has no SQL of its own; ...`: applying a derived condition to a query directly.

From the compiler, on what a condition emitted:

- `Condition [%s] on schema [%s] may only add where clauses, but it emitted a [%s]; ...`
- `Condition [%s] on schema [%s] added no where clause; a condition must add at least one where clause, return true/false to decide the outcome outright, or return null to answer unknown.`
- `Condition [%s] on schema [%s] returned null, answering unknown, but also added a where clause; return the builder it constrained, or answer unknown without constraining it.`

That last one is nearly always a missing `return`.

## Row keys, `InvalidArgumentException`

From validation, and from the compiler, which makes the same checks for handles
that never passed through the parser:

- `A %s(...) reference targets a specific row of schema [%s], but [%s] has no way to name one; …`
- `A %s(...) reference to schema [%s] supplies %d row-key argument(s), but that schema's row key requires at least %d.`

From the key's own dispatch:

- `The row key for schema [%s] requires at least %d argument(s), but %d were supplied.`
- `The row key for schema [%s] must return the query it constrained, or null to answer unknown; it returned a [%s].`

From the schema's declaration:

- `Schema [%s] declares a condition attribute on matchKey(), which is the schema's row key and not part of its rule vocabulary; …`
- `Schema [%s] must accept a [%s] as the first parameter of matchKey().`
- `Schema [%s] names model [%s] and also defines a virtualTable(); a schema draws its rows from one or the other. …`
- `Schema [%s] names model [%s] and also declares a key [%s]; a model answers for its own key, so drop the constant. …`

From a condition over rows with no key column:

- `BadMethodCallException`: `These rows have no key column of their own, so row() must be given a column name; …`

An empty argument list is not an error in itself: it addresses a row by a key
requiring no arguments. Only `null` means a no-row check.

## Cross-schema row selectors, `InvalidArgumentException`

- `The row selector for schema [%s] is a [%s], which is not that schema's model [%s]; pass that schema's own model or a row key.`
- `The row selector for schema [%s] is a [%s], which cannot identify a row; pass a key, that schema's model, or a @column/@sql reference.`
- `A can(...) reference to schema [%s] specifies a row target that is null; supply a row id or a @context reference, or drop the row selector.`

## Cycles and depth

- `Cross-schema can(...) cycle detected: … A can(...) reference must not, directly or transitively, depend on the ability being compiled.`
- `Warrant compilation exceeded the maximum nesting depth of 64.`: `can(...)` hops
  and `check(...)` dispatches nested too deep. The message lists the chain.
- `Expansion exceeded the maximum nesting depth of 64.`: a template or derived
  condition that expands into itself with the same arguments. The message lists
  the chain, collapsing repeats.

See [depth and cycles](/diagnosis/depth-and-cycles/).

## Context, `InvalidArgumentException`

```text
Schema [%s] requires context key(s) [%s]; supply them at the check or via defaultContext().
```

An *optional* key that is absent does not throw. It is passed to its condition as
`null`, and standard SQL logic applies, which is fail-closed.

## Expansion

Thrown while a rule set is expanded, by the guard before its first check and by
`validate()`:

- `InvalidArgumentException`: `Schema [%s] declares no rule template [%s], named by an @include.`
- `InvalidArgumentException`: `Rule template [%s] requires %d argument(s), but the @include supplies %d.`
- `RuntimeException`: `Rule template [%s::%s] must answer with a string or a WarrantRuleTemplate, got %s.`
- `InvalidArgumentException`: `Condition [%s] on schema [%s] requires at least %d argument(s), but the rule supplied %d.` (a derived condition given too few arguments)
- `RuntimeException`: `Derived condition [%s::%s] must answer with an expression, a WarrantConditionBuilder, rule text, a bool or null; got %s.`
- `RuntimeException`: `Derived condition [%s::%s] answered with rule text that is not a condition expression: ...` (the parser's own error follows, and is the previous exception)
- `InvalidArgumentException`: `Condition [%s] on schema [%s] returned a condition builder with no terms, which would silently match every row; ...`
- `RuntimeException`: `Expansion exceeded the maximum nesting depth of 64.`

The compiler rejects a derived condition that never went through expansion:
`Condition [%s] on schema [%s] is a derived condition and reached the compiler unexpanded; ...`

## Rule templates

- `An @include outside an ability block must name the abilities it applies to`
- `An @include inside an ability block may not name abilities`

## Registry

- `InvalidArgumentException`: `Schema [...] is registered under more than one schema key [...]`
- `OutOfBoundsException`: `No Warrant %s registered for reference [%s].`

Deferred to first resolution, since each requires loading a class:

- `LogicException`: `Schema key [...] is registered to [...], which is not a Warrant\Schema\WarrantSchema.`
- `LogicException`: `Schema [...] names model [...], which is not an Eloquent model.`
- `LogicException`: `Schema [...] names model [...], but that model does not use the Warrant\HasWarrantSchema trait, ...`
- `LogicException`: `Model [...] must declare warrantSchema() as \`public static\`.`
- `LogicException`: `Schema [...] names model [...], but that model names schema [...]; a schema and its model must name each other.`
- `LogicException`: `Model [...] must name a Warrant\Schema\WarrantSchema, but names [...].`

The model-end pair is what catches a subclass inheriting `warrantSchema()` from its
parent.

## Resolver

- `The rule resolver was asked for schema [%s] but returned a rule set targeting [%s].`
- `Implicit rule set for schema [%s] targets a different schema [%s].`
- `Cannot merge rule sets for different schemas: [%s] and [%s].`

## Authorization, `WarrantAuthorizationException`

Thrown by `authorize()` and `authorizeAny()`. Extends Laravel's
`AuthorizationException`, so it renders as a 403.

```php
public readonly ?WarrantDenialContext $denial;
```

`$denial` is a diagnosed denial context, or null for a generic denial. See
[denial messages](/rules/denial-messages/).

## Middleware

| Condition | Exception |
|---|---|
| no abilities supplied | `InvalidArgumentException`, *Access control middleware requires at least one ability.* |
| no abilities, reachability guard | `InvalidArgumentException`, *Warrant reachability middleware requires at least one ability.* |
| parameter is not a model | `InvalidArgumentException`, *must resolve to a model instance.* |
| target resolves to no schema | `InvalidArgumentException`, *Unable to resolve access control schema for [...]* |
| target is not a key, reachability guard | `InvalidArgumentException`, *reachability guards take a schema key.* |
| unauthenticated or unauthorized | `WarrantAuthorizationException`, 403 |

## No authenticated user

- `InvalidArgumentException`: `Warrant requires an authenticated user or an explicit user instance.` from the engine entry points.
- `LogicException`: from the query scopes and `loadUserAbilities()`.

## Writing rules back out, `LogicException`

From `WarrantSyntax::toSyntax()` when a rule cannot be rendered inline. Use
`toBoundSyntax()`:

- `A constant boolean expression has no rule-language representation.`
- `Condition parameter of type %s cannot be written inline; use toBoundSyntax().`
- `NAN/INF cannot be written inline; use toBoundSyntax().`
- `Float %s requires exponent notation, unsupported inline; use toBoundSyntax().`

Also from the builder:

- `toRule()` with neither `theyCan` nor `theyCannot`.
- An empty `check` predicate closure, which cannot fall back to `false`.

## Configuration and drivers, `RuntimeException`

- `No Warrant rule resolver configured. Set warrant.rule_resolver to a class implementing Warrant\Rules\RuleResolver.`
- `Warrant ability selection does not support the [%s] database driver.`

From `WarrantSyntax::parseFile()`, an `InvalidArgumentException`:

- `Unable to read Warrant rule file [%s].`
