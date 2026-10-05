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

- `const model` — managed Eloquent model (or `''` for a schema with no model); the model must name the schema back via `HasWarrantSchema`
- schema key — declared as the array key in `config/warrant.php`, not on the class
- `#[Ability] const X = '...'` — declare an ability (add `requiredContext: [...]` for per-ability required context keys)
- `#[RequiredContext] const X = '...'` — mark a context key required on every check (context keys need no declaration to be *used*)
- `#[RowCondition]` / `#[GlobalCondition]` methods — declare conditions
- `public function implicitRules(): array|WarrantRuleSet` — always-on rules
- `protected function defaultContext(): array` — default check-time context
- `public function forbiddenDenialMessage(WarrantDenialContext $c): string|Throwable|null` — message when a `cannot` denied
- `public function ungrantedDenialMessage(WarrantUngrantedContext $c): string|Throwable|null` — message when nothing granted
- `Schema::guard($user)` — schema-bound engine for this schema (= `Warrant::forSchema(Schema::class, $user)`)

## Build rules

See [Rule-building API](/reference/rule-building-api/).

- `WarrantRuleSet::fromSyntax(string $syntax, Model|WarrantSchema|string|null $schema = null, array $bindings = [])`
- `WarrantRuleSet::fromRules(Model|WarrantSchema|string $schema, WarrantRule|WarrantRuleBuilder|array ...$rules)`
- `WarrantRuleSet::build(Model|WarrantSchema|string $schema, Closure $callback)`
- `WarrantRule::fromSyntax(string $syntax, Model|WarrantSchema|string|null $schema = null, array $bindings = [])`
- `WarrantParser::parse(string $source, array $bindings = []): WarrantRule[]`
- `WarrantParser::parseSingleRule(string $source, array $bindings = []): WarrantRule`
- `WarrantRule::build()` — fluent builder: `->if/andIf/orIf/ifNot/…`, `->ifCan/->ifCheck` (+ `and`/`or` forms, with `Ref::context/column/sql`), `->theyCan/theyCannot`, `->toRule()`

## Provide rules

Implement `Warrant\Rules\RuleResolver` — see [Providing rules](/guides/resolvers/).

- `resolve(RuleResolutionContext $context): WarrantRuleSet`
- context: `->user`, `->schemaKey`, `->schema`, `->model`
- register in `config/warrant.php` → `rule_resolver`, `schemas`

## Check access

Reach the engine three ways — see [Checking API](/reference/checking-api/). Checks
live on the engine, not the model/schema.

- Facade (target names the schema): `Warrant::can($abilities, $target, $context = [], $user = null): bool`
- `Warrant::canAny(...)` — ANY (ALL is `can`; there is **no** `matchMode` argument)
- `Warrant::cannot(...)`, `Warrant::authorize(...): void`, `Warrant::authorizeAny(...): void` — throwing; 403 on denial ([denial messages](/guides/denial-messages/))
- `Warrant::abilities($target, $context = [], $user = null): array`
- `Warrant::flush($user = null): void` — drop memoized rule sets for one user, or (with no argument) all of them ([resolution lifetime](/guides/resolvers/#resolution-lifetime))
- target forms: `$model` (row), `[Model::class|Schema::class, $id]` (row by key), `Model::class` / `Schema::class` / `'schema_key'` (no-target)
- User-bound guard: `Warrant::guard($user)` or `$user->warrant()` (`use Warrant\AuthorizesWithWarrant`) → `WarrantGuard` (`->can/canAny/cannot/authorize/authorizeAny/abilities`, `->forSchema(...)`)
- Schema-bound guard: `Warrant::forSchema($schemaOrModel, $user)` or `Schema::guard($user)` → `WarrantGuardForSchema` (same methods; target is just the row or `null`)
- Model query helpers (`use Warrant\HasWarrantSchema`) — these **keep** `AbilityMatchMode`:
  - `->userHasAbility($abilities, $user = null, $matchMode = ALL, $context = [])` — query scope
  - `->selectUserAbilities($user = null, $selectedAbilitiesKey = 'abilities', ?array $onlyAbilities = null, $context = [])` — query scope
  - `$model->loadUserAbilities($user = null, $selectedAbilitiesKey = 'abilities', $context = [])` — attach the ability list to an instance
- `context:` — values for the rules' `@context` keys, merged over `defaultContext()`
- `Warrant\AbilityMatchMode::ALL | ANY` — used by scopes, middleware, and the lower-level query methods
- Laravel Gate — `$user->can($ability, $target)`, `Gate::authorize`, `@can`, and `can:` route middleware resolve Warrant abilities ([details](/guides/checking-access/#laravels-gate)); toggle with `register_gate`

## Reachability

Structural check — no conditions, no SQL, no `context:` (user still required); schema comes first, no `matchMode` (use `*Any`). See [Reachability](/guides/reachability/).

- `Warrant::reachabilityOf($schema, $ability, $user = null): Reachability`
- `Warrant::couldEverHave($schema, $abilities, $user = null): bool` — `!== NEVER` (+ `couldEverHaveAny`)
- `Warrant::alwaysHas($schema, $abilities, $user = null): bool` — `=== ALWAYS` (+ `alwaysHasAny`)
- `Warrant::neverHas($schema, $abilities, $user = null): bool` — `=== NEVER` (+ `neverHasAny`)
- `Warrant::possibleAbilities($schema, $user = null) / guaranteedAbilities(...) / impossibleAbilities(...): array`
- also on `Warrant::guard($user)->...` (schema-first) and `Warrant::forSchema($schema, $user)->...` (no schema arg)
- `Warrant\Reachability::NEVER | MAYBE | ALWAYS`

## Middleware

`Warrant\Middleware\WarrantMiddleware` — see [Middleware API](/reference/middleware-api/).

- `::string($target, $abilities, $matchMode = ALL)`
- `::guard($target, $abilities, Closure $routes, $matchMode = ALL)`
- `::canView / canCreate / canUpdate / canDelete / canArchive($target, ?Closure)`
- reachability guards: `::couldEver / always / never($target, $abilities, ?Closure, $matchMode = ALL)` — target-free, key-only ([reachability](/guides/reachability/))
- aliases `warrant.could-ever[.any]`, `warrant.always[.any]`, `warrant.never[.any]` — mode/match-mode in the alias; params are `schemaKey,abilities...`

## Config — `config/warrant.php`

- `rule_resolver` — class implementing `Warrant\Rules\RuleResolver` (no default; required)
- `schemas` — array of schema class-strings (registration is mandatory)
- `register_gate` — register the `Gate::before` hook so abilities resolve through Laravel's Gate (default `true`)
