---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Core concepts
description: The vocabulary of Warrant — abilities, conditions, rules, rule sets, resolvers, schemas, and the APIs built on them.
sidebar:
  order: 3
---

Warrant keeps three things separate, and that separation of concerns is what gives you Warrant superpowers 💪🏽:

- **Schema** — the _vocabulary_ (abilities + conditions) for one resource.
- **Rules** — the _policy_, written as plain strings that reference that vocabulary.
- **Resolver** — the _glue_ that hands Warrant the rules for the current request.

Everything below is one of those three or something built on top of them.

## Ability

An **ability** is a single action you can check for — `view`, `update`, `approve`.
Abilities are the only things a rule can grant or deny; you declare whatever verbs
your domain needs as `#[Ability]` constants on a schema.

```php
#[Ability] public const VIEW = 'view';     // on DocumentSchema
```

Once declared, a rule can name it — `they can view`. There's no fixed list; see
[Abilities](/guides/schemas/#abilities).

## Condition

A **condition** is a named test a rule may put after `if` — `is_self`,
`manages_team`, `is_admin`. Each is a method on the schema that **emits SQL** (or,
for a **global** condition, returns a `bool`), so conditions are the bridge between
the rule language and your database:

```php
#[TargetedCondition]                                    // constrains which rows match
public function isSelf(TargetedConditionContext $c): Builder
{
    return $c->query->where('documents.user_id', $c->user->getKey());
}

#[GlobalCondition]                                      // about the user / the world
public function isAdmin(GlobalConditionContext $c): bool
{
    return $c->user->is_admin;
}
```

Conditions can take **arguments** straight from the rule string — here `'sales'`
arrives on `$c->arguments`:

```text
if in_team('sales') they can view
```

See [Conditions](/guides/conditions/), [targeted vs. global](/guides/conditions/#targeted-vs-global),
and [arguments](/guides/conditions/#arguments).

## Rule

A **rule** is one line of policy: an optional `if <condition expression>`, then
`they can` or `they cannot`, then the abilities it affects.

```text
if is_self or manages_team they can view, update
```

Throughout the language, **"they" is the current user** — the one your resolver was
asked about. A rule never says what _everyone_ can do, only what _this_ user can do
with the resource it's scoped to. Rules are plain strings — data, not code — so they
can live in a table, in config, on a JWT claim.

You construct a single rule two ways. Parse one from the DSL:

```php
WarrantRule::fromSyntax('if is_self or manages_team they can view, update');
```

Or build it fluently with the [rule builder](/guides/rule-builder/) — the same rule,
composed in PHP:

```php
WarrantRule::build()
    ->if('is_self')->orIf('manages_team')
    ->theyCan('view', 'update')
    ->toRule();
```

See the [rule language](/guides/rule-language/) for the full syntax.

## Rule set

A **rule set** (`WarrantRuleSet`) is the collection of rules that apply to one
resource for one user — what your resolver returns and what Warrant compiles. There
are three ways to construct one.

Parse a whole multi-rule string with `fromSyntax`:

```php
WarrantRuleSet::fromSyntax('documents', '
    if is_self or manages_team they can view, update
    if is_locked and not is_admin they cannot update
    if is_admin they can *
');
```

Compose it from already-built `WarrantRule` objects with `fromRules`:

```php
WarrantRuleSet::fromRules('documents',
    WarrantRule::fromSyntax('if is_self they can view'),
    WarrantRule::build()->if('is_admin')->theyCan('view', 'update')->toRule(),
);
```

Or build the whole set fluently, where each `$rule()` call appends a rule:

```php
WarrantRuleSet::build('documents', function ($rule) {
    $rule()->if('is_self')->orIf('manages_team')->theyCan('view', 'update');
    $rule()->if('is_admin')->theyCan('*');
});
```

See the [Rule-building API](/reference/rule-building-api/) for all three.

## Rule resolver

The **resolver** is the one class you write that, at request time, hands Warrant the
rule set for the current user and resource. This is where "rules are data" pays off:

```php
class DatabaseRuleResolver implements RuleResolver
{
    public function resolve(RuleResolutionContext $context): WarrantRuleSet
    {
        $rules = DB::table('role_rules')
            ->where('role_id', $context->user->role_id)
            ->where('resource', $context->schemaKey)   // e.g. 'documents'
            ->pluck('rule');

        return WarrantRuleSet::fromSyntax($context->schemaKey, $rules->implode("\n"));
    }
}
```

Warrant owns no tables and has no opinion about where rules live — it only asks your
resolver for a `WarrantRuleSet`. You can also add [implicit rules](/guides/resolvers/#implicit-rules)
that always apply. See [Providing rules](/guides/resolvers/).

## Schema

A **schema** is the vocabulary for one resource — the abilities that exist and the
conditions a rule may test — in a single PHP class tied to a model:

```php
class DocumentSchema extends WarrantSchema
{
    public const model = Document::class;

    #[Ability] public const VIEW = 'view';
    #[Ability] public const UPDATE = 'update';

    #[TargetedCondition]
    public function isSelf(TargetedConditionContext $c): Builder
    {
        return $c->query->where('documents.user_id', $c->user->getKey());
    }

    #[GlobalCondition]
    public function isAdmin(GlobalConditionContext $c): bool
    {
        return $c->user->is_admin;
    }
}
```

A **schema is not a policy**: it decides nothing, it only declares the words your
rules may use, and Warrant validates every rule against it at compile time. A
[schema with no model](/guides/schemas/#schemas-with-no-model) answers only
no-target checks. See [Schemas](/guides/schemas/).

## Grants and denials

For each ability, Warrant `OR`s the `can` rules and subtracts the `cannot` rules.
The one combining rule: **a `cannot` always beats a `can`**, and an ability with no
`can` is denied by default.

Everyone may view, but never a locked row — the `cannot` wins:

```text
they can view
if is_locked
they cannot view
```

Order never matters. See [Grants and denials](/guides/grants-and-denials/).

## Check-time context

Some values a condition needs aren't fixed when your resolver builds the rules —
they're only settled when you ask the actual question, "can this user access this?"
Think an active tenant, an academic year, or an as-of date that the caller chooses
per check. Those come in as **context keys**: named values you pass to the check,
which a rule (or a condition) can then use.

First declare the key on the schema, alongside your abilities:

```php
#[ContextKey] public const WORKSPACE = 'workspace_id';
```

Then supply it when you check — the value lives on the request, not in the rule:

```php
Document::query()->userHasAbility('view', context: ['workspace_id' => 42])->get();
```

A condition reads it one of two ways. Thread it in as an argument from the rule with
`@context`:

```text
if in_workspace(@context workspace_id) they can view
```

...or, if the condition is inherently tied to the frame, skip the rule and read the
ambient bag directly with `$c->context['workspace_id']` — then the rule needn't
mention the key at all. Keys can be `required` or optional, which matters for how a
missing value behaves; see [Check-time context](/guides/context/).

## Checking access

Once your model uses the `HasWarrantSchema` trait, you ask about the current user's
access through one API:

```php
// A single boolean value representing whether or not the current user can
// view this document
$document->userHasAbility('view');

// The throwing sibling — aborts with a 403 (and the rule's denial message)
// if the user can't view it
Document::authorize('view', $document);

// A scope that filters a list of documents to just the ones the user may view
Document::query()
    ->userHasAbility('view')
    ->paginate();

// selectUserAbilities adds a json column to every row that looks something like
// ["view", "update"] so you know what the user can 'do' to every document
Document::query()
    ->selectUserAbilities()
    ->get();
```

These abilities also resolve through Laravel's Gate — `$user->can('view', $document)`,
`Gate::authorize`, `@can`, and the `can:` route middleware all work.

See [Checking access](/guides/checking-access/) and the [Checking API](/reference/checking-api/).

## Route middleware

The same rules can guard a route before your controller runs — targeted on a
route-model-bound record, or a [no-target check](/guides/middleware/#no-target-checks)
with no row:

```php
// middleware to guard your routes
WarrantMiddleware::guard('document', 'view', function () {
    Route::get('/documents/{document}', [DocumentController::class, 'index']);
});
```

See [Route middleware](/guides/middleware/).

## Reachability

Distinct from "can they act on _this_ row right now?" is "**could they ever**?" — a
structural question used to hide UI that's impossible for a user, without a query
per link:

```php
Document::abilityReachability('update'); // Reachability::NEVER | MAYBE | ALWAYS
```

See [Reachability](/guides/reachability/).

## It all compiles to SQL

The idea that ties the rest together: Warrant never evaluates rules in PHP. A rule
set compiles to one SQL predicate per ability — `if is_self they can view` becomes
roughly:

```sql
select * from documents where documents.user_id = 42
```

That's why the boolean check, the list filter, and the per-row abilities can't
disagree — there's one source of truth. (The real output is a little more careful
than this; see [How it compiles to SQL](/guides/how-it-compiles/).)
