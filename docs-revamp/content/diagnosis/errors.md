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
- `Expected 'context' after '@'.`, `Expected a context key after '@context'.`
- `Expected 'can' or 'cannot' after 'they'.`
- `Expected at least one 'they can ...' or 'they cannot ...' clause.`
- `Expected ')' to close the group.`, `Expected ')' to close the condition arguments.`
- `Reserved word '%s' cannot be used as a name; expected %s.`
- `Expected a rule.`, `Expected a single rule but found multiple.`

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

Attaching a denial message to a rule with no `theyCannot` clause is rejected here
too.

Type guards:

- `fromRules expects WarrantRule or WarrantRuleBuilder instances, got %s.`
- `validateAll expects WarrantRuleSet instances, got %s.`
- `fromRuleSets expects WarrantRuleSet instances, got %s.`

:::note[Two "unknown ability" messages]
*is not declared by the schema* comes from validating a rule set. A different one
fires at check time when the **requested** ability is not declared:
*Ability [%s] is not defined on schema [%s].*
:::

## Conditions and reflection, `InvalidArgumentException`

Thrown the first time a schema's conditions are reflected:

- `Condition method [%s::%s] must not declare duplicate condition attributes.`
- `Condition method [%s::%s] cannot declare both #[RowCondition] and #[GlobalCondition].`
- `Condition method [%s::%s] must resolve to a non-empty condition key.`
- `Condition method [%s::%s] must accept a [%s] as its first parameter.`
- `Schema [%s] has no rows and does not support targeted checks; use a no-target check instead.`
- `Schema [%s] has rows but no way to name one, so it does not support targeted checks; declare `const key` …`

## Applying a condition

From the resolver:

- `BadMethodCallException`: `Condition [%s] is not defined on schema [%s].`
- `Condition [%s] on schema [%s] requires a target row.`
- `Condition [%s] on schema [%s] requires at least %d argument(s), but the rule supplied %d.`

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
- `... exceeded the maximum nesting depth`: worded for a template expansion on its
  own, or for the whole compile when one is under way.

## Context, `InvalidArgumentException`

```text
Schema [%s] requires context key(s) [%s]; supply them at the check or via defaultContext().
```

An *optional* key that is absent does not throw. It is passed to its condition as
`null`, and standard SQL logic applies, which is fail-closed.

## Rule templates

- `Schema [%s] declares no rule template [%s]`
- `Rule template [%s] requires N argument(s), but the @include supplies M`
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

From `toSyntax()` when a rule cannot be rendered inline. Use `toBoundSyntax()`:

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
- `Cannot read Warrant rule file [%s].`
- `Failed to read Warrant rule file [%s].`
