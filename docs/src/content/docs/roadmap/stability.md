---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Stability and versioning
description: What is covered, what is internal, and what a beta means for you.
sidebar:
  order: 2
---

Warrant is in **beta**. Expect API changes between releases, and read the release
notes before upgrading.

## What counts as the public surface

The things this documentation covers as API:

- the `Warrant` facade and the two guards;
- `WarrantSchema` and its hooks, including `matchKey()`, `virtualTable()`,
  `implicitRules()`, `defaultContext()`, and the denial-message hooks;
- the attributes: `#[Ability]`, `DeclaresAbility`, `#[RowCondition]`,
  `#[GlobalCondition]`, `#[RequiredContext]`, `#[RuleTemplate]`;
- the condition context objects and their properties;
- `HasWarrantSchema`, its scopes, and `warrantQualifyColumn()`;
- `WarrantRuleSet`, `WarrantRule`, `RuleSetGroup`, the builders, and `Ref`;
- `RuleResolver` and `RuleResolutionContext`;
- `WarrantMiddleware` and the registered aliases;
- the exception classes and the denial-context objects;
- **the rule language itself**, which is the most important one, because rule text
  is data you have stored.

## What is internal

Anything under `Warrant\DSL\` other than what is named above, particularly the
parser internals, the AST node classes, and the compiler. `CompilationResult` and
`Decision` are documented because tooling needs them, and they are the most likely
of the documented surface to move.

Also internal: the exact SQL emitted. The semantics are the contract; the text is
not. A test asserting on generated SQL strings is testing an implementation detail.
See [testing rules](/testing/rules/).

## The rule language

Stored rule text is the surface that costs most to change, because it is data in
your database rather than code you can refactor.

Two habits protect you:

Keep a [CI test](/supplying-rules/validation/) validating every stored rule. A
language change that breaks one then fails your build rather than a request.

Keep [`toSyntax()`](/reference/warrant-rule-set/) in mind as a migration tool. A
rule set round-trips through the language, so a mechanical rewrite of stored rules
is a script rather than a project.

## During beta

Ability and condition semantics, and the combination rules, are the parts least
likely to change. The shape of an answer, the fail-closed direction of unknown, and
the absoluteness of a `cannot` are load-bearing enough that changing them would be
a different library.

The parts most likely to move are the newer ones: ability blocks, rule templates,
derived conditions, and the low-level compilation surface.

## Reporting something

Issues and discussion are on
[GitHub](https://github.com/patrickjames242/laravel-warrant/issues). A report is
most useful with the rule text, the schema's relevant conditions, and the SQL from
`toRawSql()`.

Laravel Warrant is an independent open-source project. It is not affiliated with,
maintained by, or endorsed by the Laravel team.
