---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: API cheat sheet
description: The whole Warrant surface on one page.
sidebar:
  order: 1
---

The whole surface at a glance. Follow the links for full signatures and behaviour.

## Define a schema

`extends Warrant\Schema\WarrantSchema` — see [Schema API](/reference/schema-api/).

- `const model` — managed Eloquent model (or `''` for a schema with no model)
- `const schemaKey` — optional schema-key override (**required** with no model)
- `#[Ability] const X = '...'` — declare an ability
- `#[ContextKey] const X = '...'` — declare a check-time context key (required by default; `required: false` to opt out)
- `#[RowCondition]` / `#[GlobalCondition]` methods — declare conditions
- `protected function implicitRules(): array` — always-on rules
- `protected function defaultContext(): array` — default check-time context
- `protected function forbiddenDenialMessage(WarrantDenialContext $c): string|Throwable|null` — message when a `cannot` denied
- `protected function ungrantedDenialMessage(WarrantUngrantedContext $c): string|Throwable|null` — message when nothing granted

## Build rules

See [Rule-building API](/reference/rule-building-api/).

- `WarrantRuleSet::fromSyntax(Model|WarrantSchema|string $schema, string $syntax, array $bindings = [])`
- `WarrantRuleSet::fromRules(Model|WarrantSchema|string $schema, WarrantRule|WarrantRuleBuilder|array ...$rules)`
- `WarrantRuleSet::build(Model|WarrantSchema|string $schema, Closure $callback)`
- `WarrantRule::fromSyntax(string $syntax, array $bindings = [])`
- `WarrantParser::parse(string $source, array $bindings = []): WarrantRule[]`
- `WarrantParser::parseSingleRule(string $source, array $bindings = []): WarrantRule`
- `WarrantRule::build()` — fluent builder: `->if/andIf/orIf/ifNot/…`, `->theyCan/theyCannot`, `->toRule()`

## Provide rules

Implement `Warrant\RuleResolver` — see [Providing rules](/guides/resolvers/).

- `resolve(RuleResolutionContext $context): WarrantRuleSet`
- context: `->user`, `->schemaKey`, `->schema`, `->model`
- register in `config/warrant.php` → `rule_resolver`, `schemas`

## Check access

`use Warrant\HasWarrantSchema` on the model — see [Checking API](/reference/checking-api/).

- `Model::userHasAbilities($abilities, $target = null, $user = null, $matchMode = ALL, $context = []): bool`
- `Model::authorize($abilities, $target = null, $user = null, $matchMode = ALL, $context = []): void` — throwing sibling; 403 on denial ([denial messages](/guides/denial-messages/))
- `Model::getUserAbilities($target = null, $user = null, $context = []): array`
- `->userHasAbility($abilities, $user = null, $matchMode = ALL, $context = [])` — instance method **and** query scope (`scopeUserHasAbility`)
- `->selectUserAbilities($user = null, $selectedAbilitiesKey = 'abilities', ?array $onlyAbilities = null, $context = [])` — query scope
- `$model->loadUserAbilities($user = null, $selectedAbilitiesKey = 'abilities', $context = [])` — attach the ability list to an instance
- `context:` — values for the rules' `@context` keys, merged over `defaultContext()`
- `Warrant\AbilityMatchMode::ALL | ANY`
- Laravel Gate — `$user->can($ability, $target)`, `Gate::authorize`, `@can`, and `can:` route middleware resolve Warrant abilities ([details](/guides/checking-access/#laravels-gate)); toggle with `register_gate`

## Reachability

Structural check — no conditions, no SQL, no `context:` (user still required). See [Reachability](/guides/reachability/).

- `Model::abilityReachability($ability, $user = null): Reachability`
- `Model::userCouldEverHave($abilities, $user = null, $matchMode = ALL): bool` — `!== NEVER`
- `Model::userAlwaysHas($abilities, $user = null, $matchMode = ALL): bool` — `=== ALWAYS`
- `Model::userNeverHas($abilities, $user = null, $matchMode = ALL): bool` — `=== NEVER`
- `Model::getUserPossibleAbilities($user = null) / getUserGuaranteedAbilities(...) / getUserImpossibleAbilities(...): array`
- `Warrant\Reachability::NEVER | MAYBE | ALWAYS`
- facade form: `Warrant::userCouldEverHave('documents', 'update', $user)`

## Middleware

`Warrant\WarrantMiddleware` — see [Middleware API](/reference/middleware-api/).

- `::string($target, $abilities, $matchMode = ALL)`
- `::guard($target, $abilities, Closure $routes, $matchMode = ALL)`
- `::canView / canCreate / canUpdate / canDelete / canArchive($target, ?Closure)`
- reachability guards: `::couldEver / always / never($target, $abilities, ?Closure, $matchMode = ALL)` — target-free, key-only ([reachability](/guides/reachability/))
- aliases `warrant.could-ever[.any]`, `warrant.always[.any]`, `warrant.never[.any]` — mode/match-mode in the alias; params are `schemaKey,abilities...`

## Config — `config/warrant.php`

- `rule_resolver` — class implementing `Warrant\RuleResolver` (no default; required)
- `schemas` — array of schema class-strings (registration is mandatory)
- `register_gate` — register the `Gate::before` hook so abilities resolve through Laravel's Gate (default `true`)
