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
| parse | rule syntax |
| validate | bindings, and ability and condition names against the schema |
| expansion | templates and derived conditions resolve, answer sensibly, and terminate |
| compile | everything validation checks, again, for rules that skipped it |
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

- `Unexpected character %s.`, or `Unexpected byte 0x%02X.` for a byte that is not valid UTF-8
- `Unterminated string literal.`
- `Invalid escape sequence "\%s"; only \', \", and \\ are allowed.`
- `Expected 'context', 'column', 'sql', or 'include' after '@'.`, `Expected a context key after '@context'.`
- `Expected 'can' or 'cannot' after 'they'.`
- `Expected at least one 'they can ...' or 'they cannot ...' clause.`
- `'because' may only follow a 'they cannot ...' clause, not 'they can ...'.`
- `A can/cannot clause inside an ability block may not name abilities; the block header already names them.`
- `Expected ')' to close the group.`, `Expected ')' to close the condition arguments.`
- `Reserved word '%s' cannot be used as a name; expected %s.`

### The shape of the source

One parse reads every form, so these are about how the pieces sit together:

- `` A `{ ... }` block needs a `for <schema>` header before it. ``
- `` Rules without a `for` header cannot be followed by a `for` block; put them in a block of their own. ``
- `` %s names abilities, but the rules before it name none; rules either all name their abilities or all leave them to be named where the rules are placed. `` (or `names none` / `name theirs`; `%s` is `This can/cannot clause`, `This @include` or `An ability block`)
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

## Validation, `RuleValidationException`

Extends `InvalidArgumentException`. Each points at the text it is about, in the
source that text was written in.

### Bindings

- `No binding provided for ":%s".`
- `Binding(s) provided but never used: %s.`
- `The binding [:%s] stands in an ability list, so its value is an ability name or a non-empty list of them; got %s.`
- `The binding [:%s] stands for the one ability a can(...) reference asks about, so its value is an ability name; got %s.`

Each needs the values, so an editor, which has none, reports none of them.

### Names

- `Ability [%s] is not declared by the schema.`
- `Condition [%s] is declared by neither schema [%s] nor any global schema.`
- `Condition [%s] is declared by no global schema; the text global schema [%s] answers with looks only in the global schemas.`: a name in what a global schema's template or derived condition answered with, which is looked up in the global schemas alone.
- `Condition [%s] requires at least %d argument(s), but the rule supplied %d.`
- `Global schemas [%s] and [%s] both declare %s [%s]; a name may be declared by only one global schema, since the one listed first would always be found. Rename one of them.`: a `LogicException` from any lookup that reaches the global schemas, and a `duplicate-global-name` diagnostic on the later declaration in the editor.
- `Rule template [%s], named by an @include, is declared by neither schema [%s] nor any global schema.` (or `is declared by no global schema; …`, for an `@include` in a global schema's answer)
- `Rule template [%s] requires %d argument(s), but the @include supplies %d.`

A bound ability is checked against the schema too, when the values are given.

Context keys need no declaration to be referenced, so there is no unknown-context-key
error.

### Ability lists

- `%s is written more than once among the abilities %s; write it once.` (`%s` is `Ability [view]` or `Binding [:more]`, then `this rule grants`, `this rule denies`, `this ability block names` or `this @include names`)
- `` `*` stands for every ability, so nothing else is written beside it among the abilities %s. ``

Both judge the list as written, not the values its bindings hold.

Type guards:

- `A rule set holds rules, ability blocks and includes, got %s.`
- `Every can/cannot clause and @include outside an ability block names the abilities it applies to; the rules for [%s] hold one that names none.` (or `these rules hold one…` when no schema is known)

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
- `Schema [%s] declares condition [%s] more than once; conditions of every kind share one set of names.`

Thrown the first time a schema is constructed, for a construct its type does not
provide or a way of naming rows it lacks:

- `Method [%s::%s] is a #[RowCondition], but schema [%s] has no rows. Extend ModelSchema or QuerySchema for a schema that does.`
- `Constant [%s::%s] declares an ability, but schema [%s] has no abilities. …`
- `Constant [%s::%s] declares required context, but schema [%s] has no context policy. …`
- `Schema [%s] has no way to find one of its rows; declare key() for the column or columns its rows are identified by, or a matchKey() of its own.`
- `LogicException`: `Schema [%s] names no model; declare `public const model = YourModel::class;`, or override model().`

From a check:

- `Schema [%s] has no rows and does not support targeted checks; use a no-target check instead.`
- `Schema [%s] declares no abilities, so no check can be made against it; …`

## Applying a condition

From the compiler, before a condition is called:

- `Condition [%s] is declared by neither schema [%s] nor any global schema.` (or `is declared by no global schema; …`, as validation says it)
- `Condition [%s] on schema [%s] requires at least %d argument(s), but the rule supplied %d.`
- `Condition [%s] on schema [%s] is a derived condition and reached the compiler unexpanded; …`: a rule set that skipped expansion.

From the schema, on what the condition answered:

- `Condition [%s] on schema [%s] returned a [%s]; a row or global condition answers with the query it constrained, a bool or null. Declare a condition built from other conditions #[DerivedCondition] instead, ...`

From the compiler, on what a condition emitted:

- `Condition [%s] on schema [%s] may only add where clauses, but it emitted a [%s]; ...`
- `Condition [%s] on schema [%s] added no where clause; a condition must add at least one where clause, return true/false to decide the outcome outright, or return null to answer unknown.`
- `Condition [%s] on schema [%s] returned null, answering unknown, but also added a where clause; return the builder it constrained, or answer unknown without constraining it.`

That last one is nearly always a missing `return`.

## Row keys, `InvalidArgumentException`

From validation, and from the compiler, which makes the same checks for handles
that never passed through the parser:

- `A %s(...) reference targets a specific row of schema [%s], but [%s] has no rows and cannot be row-targeted; drop the row selector.`
- `A %s(...) reference to schema [%s] supplies %d row-key argument(s), but that schema's row key requires at least %d.`

From the row matcher, wherever a row is named:

- `The row key for schema [%s] requires at least %d argument(s), but %d were supplied.`
- `The row matcher for schema [%s] must return the query it constrained, or null to answer unknown; it returned a [%s].`

From the schema's declaration:

- `Schema [%s] declares a vocabulary attribute on matchKey(), which finds the schema's rows and is not part of its rule vocabulary; …`
- `Schema [%s] must accept a [%s] as the first parameter of matchKey().`

From `column()` with no argument, over rows whose key is not one column:

- `BadMethodCallException`: `These rows are identified by no key column, so column() must be given a column name.`
- `BadMethodCallException`: `These rows are identified by several key columns, so column() must be given a column name.`

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

From a `can(... for <schema>)` or `check(... for <schema>)` whose bag, the
referenced schema's defaults and the `with` map, lacks a required key, while the
rule compiles:

```text
Schema [%s] requires context key(s) [%s]; pass them in the `with` map of the reference to [%s], or via its defaultContext().
Ability [%s] requires context key(s) [%s]; pass them in the `with` map of the reference to [%s], or via its defaultContext().
```

An *optional* key that is absent does not throw. It is passed to its condition as
`null`, and standard SQL logic applies, which is fail-closed.

## Expansion

Thrown while a rule set is expanded, by the guard before its first check:

- `InvalidArgumentException`: `Rule template [%s], named by an @include, is declared by neither schema [%s] nor any global schema.` Validation reports the same as written, before anything is expanded.
- `InvalidArgumentException`: `The binding [:%s] stands in an ability list, so its value is an ability name or a non-empty list of them; got %s.` (or `stands for the one ability a can(...) reference asks about`) Validation reports the same when it checks binding values.
- `InvalidArgumentException`: `Rule template [%s] requires %d argument(s), but the @include supplies %d.` Validation reports the same as written, too.
- `RuntimeException`: `Rule template [%s::%s] must answer with rule text, a Warrant\DSL\Parsing\ASTNodes\WarrantSyntax, a rule or @include that names no abilities, or an iterable of them; got %s.`
- `RuntimeException`: `Rule template [%s::%s] answered with a rule that names abilities; the @include that expands a template names the abilities its rules and includes take.` (or `an @include that names abilities`)
- `RuntimeException`: `Rule template [%s::%s] answered with an ability block, which names abilities; ...`
- `RuntimeException`: `Rule template [%s::%s] answered with rule text that is not rules and includes; a template answers with rules and includes that name no abilities.`
- `InvalidArgumentException`: `Condition [%s] on schema [%s] requires at least %d argument(s), but the rule supplied %d.` (a derived condition given too few arguments)
- `RuntimeException`: `Derived condition [%s::%s] must answer with an expression, a WarrantConditionBuilder, rule text, a WarrantSyntax, a bool or null; got %s.`
- `RuleValidationException`: `` %s answered under a `for` header naming schema [%s]; it belongs to schema [%s]. ``: a template's or derived condition's answer under a header naming a schema other than its own. Validation reports it.
- `RuleValidationException`: `` %s answered under a `for` header naming schema [%s]; a global schema answers with no header, since its names are looked up in the global schemas. ``: a global schema's template or derived condition answering under any header.
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

- `LogicException`: `Schema key [...] is registered to [...], which does not implement Warrant\Schema\Types\Interfaces\ISchema.`
- `LogicException`: `Schema [...] names model [...], which is not an Eloquent model.`
- `LogicException`: `Schema [...] names model [...], but that model does not use the Warrant\HasWarrantSchema trait, ...`
- `LogicException`: `Model [...] must declare warrantSchema() as \`public static\`.`
- `LogicException`: `Schema [...] names model [...], but that model names schema [...]; a schema and its model must name each other.`
- `LogicException`: `Model [...] must name a Warrant\Schema\Types\Interfaces\IModelSchema, but names [...].`

The model-end pair is what catches a subclass inheriting `warrantSchema()` from its
parent.

## Provider

- `The rules() of schema [%s] returned a rule set for schema [%s]; a schema's own rules are all for it.`
- `The rule provider returned %s; expected a rule set, a rule entry, a rule builder, rule text, or an iterable of them.` (also from a schema's `rules()`, an element of an iterable that holds no rules)

The rule provider may return rule sets for any schema; those for a schema other
than the one it was asked about are left out, not rejected.

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

- `Warrant ability selection does not support the [%s] database driver.`

From `WarrantSyntax::parseFile()`, an `InvalidArgumentException`:

- `Unable to read Warrant rule file [%s].`
  The path is the one read: for a path naming no file, that is the path with
  `.warrant` appended.
