# Upgrade guide

## A condition may answer `null`, and a forgotten `return` now throws

A condition has a fourth way to answer: **`null`, meaning unknown** — the question
has no answer here. It compiles to the third truth value, so it grants nothing and
cannot lift a `cannot`, which is what makes it the safe answer to a question
nobody can settle. See
[Answering unknown](https://laravel-warrant.dev/guides/conditions/#answering-unknown).

**The breaking part.** PHP returns `null` from a method with no `return`
statement, so a condition that constrains the query and then falls off the end is
indistinguishable from one deliberately answering unknown:

```php
#[RowCondition]
public function isOwner(RowConditionContext $c)
{
    $c->query->where($c->row('owner_id'), '=', $c->user->getAuthIdentifier());
    // no return
}
```

That used to work, because a non-bool, non-expression return fell through to the
builder. It now throws:

```
Condition [is_owner] on schema [App\Warrant\DocumentSchema] returned null,
answering unknown, but also added a where clause; return the builder it
constrained, or answer unknown without constraining it.
```

The fix is to return the builder, which is what the condition meant:

```php
return $c->query->where($c->row('owner_id'), '=', $c->user->getAuthIdentifier());
```

It throws rather than being read as unknown deliberately: silently treating it as
unknown would change what the rule grants, without a word. Search your schemas
for condition methods with no `return` on their SQL path — the error names the
condition and the schema, so a failing test points straight at it.

## `@column` references name a frame, not a schema

A `@column` reference now names **the rows a rule is about**, and the qualifier is
optional:

```text
if pay_period_matches(@column pay_period_id) they can view
```

Which table that lands on is decided per compile — the schema's own table, an
alias the caller's query used, or the frame a `can(...)` / `check(...)` hop
selected. The two-part `@column <name>.<column>` form still works, and a name may
now be either a schema key or an alias introduced with `as`.

**The qualified form is checked against what is in scope.** Naming a registered
schema whose table the query never joined used to be accepted at validation and
fail as a SQL error at execution; it is now rejected up front, with the names that
*are* available:

```text
A @column reference names [folders], which is not in scope here; the names in
scope are [timesheets, pay_periods].
```

Unknown schemas and model-less capability schemas produce the same error rather
than their previous separate messages. Existing rules that reference their own
schema — the overwhelming majority — are unaffected.

**`@column timesheets` no longer fails to parse.** It used to be a syntax error
(`Expected '.'`); it is now a reference to a column named `timesheets`. A rule
that hit that error was already broken, but it now fails later and differently.

`Ref::column()` takes one argument for the unqualified form and two for the
qualified one, so existing two-argument calls keep working.

## A host query's alias is honoured

`filterQuery()` and friends read the alias off the query you hand them:

```php
$guard->filterQuery(Document::query()->from('documents as d'), 'view');
// ... where (d.owner_id = ?)
```

Predicates previously used the model's table name unconditionally, which named a
table an aliased query does not have. Unaliased queries are unaffected.

## Unanswerable questions no longer grant

A compile can reach a question it cannot settle: a row condition with no row, a
`@column` about rows that are not in scope, a row selector that resolved to
nothing. These were treated as `false`, which **negates to `true`** — so an
unanswerable `cannot` silently stopped denying.

They are now the third truth value, which negates to itself, so an unknown
neither grants nor lifts a deny. Where it cannot fold away it reaches the SQL as a
literal `null`, and the database applies the same rule.

**`getAbilitiesWithoutTarget()` is more conservative as a result.** Given

```text
they can view
if is_owner they cannot view
```

it previously reported `view` as held, because the unanswerable deny folded to
`false` and the `not` around it to `true`. It now reports nothing: the deny cannot
be evaluated, so the ability cannot be claimed. `selectAbilitiesInQuery()` and
targeted checks compile against a row and are unaffected.

If a rule of yours relied on a `cannot` quietly not firing without a row, it will
now block. That is the direction the rest of the compiler already errs in.

## `CompilationResult::decision()` returns an enum

It answered `?bool`, with `null` meaning "not settled; ask the database" — which
left nowhere for the third truth value. It now returns a
`Warrant\DSL\Compiling\Decision`: `True`, `False`, `Unknown`, or `NeedsQuery`.

```php
// before
if ($gate->decision() === true) { ... }
$granted = $gate->decision() ?? $gate->spliceInto($q)->exists();

// after
if ($gate->decision()->grants()) { ... }
$decision = $gate->decision();
$granted = $decision->isConstant() ? $decision->grants() : $gate->spliceInto($q)->exists();
```

`grants()` is true for `True` alone — both `False` and `Unknown` deny — and
`isConstant()` is false for `NeedsQuery` alone.

## `ConditionResolver::applyCondition()` takes a row qualifier

The interface method gained a trailing `?string $rowQualifier = null`. **Any class
implementing `ConditionResolver` directly must add the parameter**, defaulted or
not, or PHP raises a fatal signature error. Schemas extending `WarrantSchema` need
no change.

It carries the name the target row answers to where the predicate lands, which is
not always the model's table: a host query's alias, or a cross-schema hop's. Pass
it to `RowConditionContext`, falling back to your own table when it is null — which
is what `ResolvesConditions` does.

## `as` is a reserved word

It introduces a handle alias (`can(view for docs(@column id) as d2)`), so it can no
longer be an exact condition, ability or schema name. Names that merely contain or
start with it — `assign`, `as_of` — are unaffected.

## References may target their own schema

`can(...)` and `check(...)` no longer reject a reference to the schema they are
written on. Two frames over one table get distinct SQL identifiers, so the
correlation is unambiguous. Recursion is still bounded: an ability that names
itself is a cycle and is rejected.

## `check(...)` predicates may nest

A predicate is read against the target's vocabulary and may now hold a nested
`check(...)` or a `can(...)`, not only conditions. A **constant** is still
rejected. The consequence is that a `check` whose predicate holds a `can` does
compile the target's rules, so it is no longer free of cycle risk by construction
— cycles are still caught.

## Models as cross-schema row selectors

The row selector in a `can(... for schema(<row>))` / `check(... for schema(<row>))`
handle now accepts the referenced schema's own model:

```text
if can(view for folders(@context folder)) they can view
```

```php
$user->warrant()->can('view', $document, ['folder' => $folder]);
```

Previously this compiled to `where "folders"."id" = '{"id":"f-1"}'` — PDO has no
rule for a model, so PHP fell back to `Model::__toString()`, which is `toJson()`.
No error was raised; the rule simply matched nothing and denied. If you worked
around that by passing `$folder->getKey()` yourself, that keeps working
unchanged.

When the model is hydrated it is also handed to the referenced schema's row
conditions as `$c->model`, so a reference whose conditions all answer in PHP
compiles to a constant and its `EXISTS` subquery disappears.

**Two new errors**, both replacing a query that used to match nothing silently:
a model belonging to a *different* schema is rejected, and so is any other object
with no meaning as a row key. Strings, ints, floats, null, `BackedEnum`,
`DateTimeInterface`, and `@column` / `@sql` references are all unaffected.

## Row conditions can be handed the target model

`RowConditionContext` gains `?Model $model` — the loaded row a check named, when
it named exactly one. A condition given it may return a `bool` and decide the
outcome in PHP, which folds away without reaching the database:

```php
#[RowCondition]
public function isSelf(RowConditionContext $c): Builder|bool
{
    if ($c->model !== null) {
        return $c->model->user_id === $c->user->getAuthIdentifier();
    }

    return $c->query->whereRaw('documents.user_id = ?', [$c->user->getAuthIdentifier()]);
}
```

**Existing conditions need no change.** `model` is a new trailing constructor
parameter, and a condition that ignores it behaves exactly as before — the SQL
Warrant emits is unchanged.

`model` is null unless the check named one specific row *and* the caller passed a
hydrated model (`Model::$exists`). Filtering a query, listing per-row abilities,
or naming the row by key all leave it null, so a row condition must always keep
its query branch.

**Breaking for custom `ConditionResolver` implementations.**
`ConditionResolver::applyCondition()` gains a trailing
`?Model $targetModel = null`, so any class implementing that interface outside the
package must add the parameter. Schemas themselves implement it through
`WarrantSchema` and need no change; this only affects a hand-written resolver.

## Integer check targets

The schema-bound guard now accepts an `int` row key directly, alongside a `Model`
instance and a string key:

```php
Warrant::forSchema(Document::class)->can('update', 42);
```

This was previously typed `Model|string|null`, so an int worked only from a file
without `declare(strict_types=1)` — where PHP silently coerced it — and was a
`TypeError` from a strict-typed caller. Nothing needs to change; calls that
already worked keep working.

The facade and `WarrantGuard` still take **no bare int**, because at that level a
bare scalar names the *schema*, not a row. Name a row schema-lessly with the
`[Document::class, $id]` tuple, which has always accepted an integer id.

One observable change: an integer key is no longer stringified on its way to the
query. `Warrant::can('view', [Document::class, 42])` used to bind `'42'` and now
binds `42`, so an integer primary key is compared as an integer instead of
relying on the database to coerce a bound string. This only affects code
inspecting query bindings — the rows matched are the same.

## Lazy schema resolution (breaking)

The schema registry no longer builds itself from the schemas it registers. It used
to read every registered schema's `const model` and call `schemaKey()` on it, and
because `schemaKey()` derived the key from the model's table (`(new $model)->getTable()`),
building the registry autoloaded every schema class, autoloaded every model class,
and *booted* every Eloquent model — on the first authorization check of every
request. An application with hundreds of schemas paid that cost to learn a set of
strings.

The registry is now an index of plain strings. A schema key maps to a schema class
in config; everything else is derived from the reference in hand, and nothing is
loaded until a schema is actually used.

Rule syntax is unchanged. Schema keys still appear in `for ... { }` headers,
`can(...)` and `check(...)` handles, `@column` references, middleware strings, and
the `RuleResolutionContext`, exactly as before, so **no stored rule text needs to
change**.

There are three changes to make.

### 1. Key the `warrant.schemas` config

The array key is now the schema key, and it is the only place a schema key is
declared.

```php
// before
'schemas' => [
    App\Warrant\DocumentSchema::class,
    App\Warrant\SettingsSchema::class,
],

// after
'schemas' => [
    'documents' => App\Warrant\DocumentSchema::class,
    'settings'  => App\Warrant\SettingsSchema::class,
],
```

Use the keys your rules already reference. If you never set `const schemaKey`, the
key your rules use is the model's table name, so use that.

### 2. Remove `const schemaKey`

`WarrantSchema::schemaKey` is gone, along with the table-name derivation behind it.
Move the value to the config array key above. `SchemaSubclass::schemaKey()` still
works and returns the same string, but it now reads the index and so needs a booted
application.

A schema with no model (`const model = ''`) no longer needs any special handling:
its key comes from config like everyone else's.

### 3. Make `warrantSchema()` static, on every model you authorize

`HasWarrantSchema::warrantSchema()` is now `abstract public static`. The trait is
also no longer optional: it is how Warrant resolves a model to its schema, so any
model that backs a schema must use it and name that schema back.

```php
// before
public function warrantSchema(): string
{
    return DocumentSchema::class;
}

// after
public static function warrantSchema(): string
{
    return DocumentSchema::class;
}
```

A schema and its model must name **each other**, which means one model has exactly
one schema. A base schema can still be extended, but each concrete schema needs its
own model class.

This is checked from whichever end Warrant was handed. Given a schema — a check by
key, or by schema class — the model it names must name that schema back. Given a
model — a row check, a query scope, `loadUserAbilities()` — the schema it names must
name that model back. The two are not interchangeable: because `warrantSchema()` is
inherited, `PublishedPost extends Post` names `PostSchema`, which names `Post`, so
only the model direction catches it. A model subclass that needs its own
authorization needs its own schema.

`HasWarrantSchema::validatedWarrantSchema()` is gone. It performed this same
cross-check just before handing the schema to the guard, which then re-checked it
in the registry; the query helpers now pass the model and let the registry check it
once, from the model end.

### 4. Update the `SchemaRegistry` namespace, if you reference it

The class moved from `Warrant\SchemaRegistry` to `Warrant\Registry\SchemaRegistry`,
alongside the concern that owns the model<->schema cross-check
(`Warrant\Registry\Concerns\VerifiesSchemaModelPairs`). Most applications never name
the class directly — `Warrant::registry()` is unchanged — so this only matters if you
type-hint or instantiate it.

### 5. Update namespaces for the schema vocabulary, if you import it explicitly

Everything a schema class declares with now lives under `Warrant\Schema`, beside
`WarrantSchema` itself:

| Before | After |
|---|---|
| `Warrant\Ability` | `Warrant\Schema\Ability` |
| `Warrant\RowCondition` | `Warrant\Schema\RowCondition` |
| `Warrant\GlobalCondition` | `Warrant\Schema\GlobalCondition` |
| `Warrant\RequiredContext` | `Warrant\Schema\RequiredContext` |
| `Warrant\StandardAbilities` | `Warrant\Schema\StandardAbilities` |
| `Warrant\WarrantDenialContext` | `Warrant\Schema\WarrantDenialContext` |
| `Warrant\WarrantUngrantedContext` | `Warrant\Schema\WarrantUngrantedContext` |

The two denial contexts move because they are the parameter types of the schema's
own `forbiddenDenialMessage()` and `ungrantedDenialMessage()` hooks. `WarrantGate`
and `HasWarrantSchema` stay where they are: the first is shared check-time
vocabulary used by the guard and compiler too, and the second is applied to models
rather than used inside a schema.

### 6. Update namespaces for the guards and the middleware, if you import them

| Before | After |
|---|---|
| `Warrant\WarrantGuard` | `Warrant\Guard\WarrantGuard` |
| `Warrant\WarrantGuardForSchema` | `Warrant\Guard\WarrantGuardForSchema` |
| `Warrant\WarrantMiddleware` | `Warrant\Middleware\WarrantMiddleware` |

Each now sits with the concerns it is built from — `Warrant\Guard\Concerns\*` and
the reachability middleware in `Warrant\Middleware\*`. The `Warrant` facade,
`Warrant::guard()`, `Warrant::forSchema()`, and the `warrant:` route-middleware
aliases are all unchanged, so this only affects code that names the classes.

### 7. Update namespaces for the rule DSL, if you import it explicitly

The rule language now lives under `Warrant\DSL`, split by phase. The classes a
consumer actually names are the rule structures and the parser:

| Before | After |
|---|---|
| `Warrant\RuleSyntaxTree\WarrantRuleSet` | `Warrant\Rules\WarrantRuleSet` |
| `Warrant\RuleSyntaxTree\WarrantRule` | `Warrant\Rules\WarrantRule` |
| `Warrant\RuleSyntaxTree\RuleSetGroup` | `Warrant\Rules\RuleSetGroup` |
| `Warrant\RuleSyntaxTree\CannotClause` | `Warrant\Rules\CannotClause` |
| `Warrant\RuleSyntaxTree\Parsing\WarrantParser` | `Warrant\DSL\Parsing\WarrantParser` |
| `Warrant\RuleSyntaxTree\WarrantSyntaxException` | `Warrant\DSL\Parsing\WarrantSyntaxException` |
| `Warrant\RuleSyntaxTree\ContextRef` / `ColumnRef` / `SqlRef` | `Warrant\DSL\Parsing\ASTNodes\…` |
| `Warrant\RuleSyntaxTree\WarrantRuleBuilder` | `Warrant\Builders\WarrantRuleBuilder` |
| `Warrant\RuleResolver` | `Warrant\Rules\RuleResolver` |
| `Warrant\RuleResolutionContext` | `Warrant\Rules\RuleResolutionContext` |
| `Warrant\RuleSyntaxTree\ConditionResolver` | `Warrant\DSL\ConditionResolver` |
| `Warrant\RuleSyntaxTree\SchemaVocabulary` | `Warrant\DSL\SchemaVocabulary` |
| `Warrant\RuleSyntaxTree\RuleSetCompiler` | `Warrant\DSL\Compiling\RuleSetCompiler` |
| `Warrant\Compiler\CompiledWhereClauseNode` | `Warrant\DSL\Compiling\WhereClause\CompiledWhereClauseNode` |

`Warrant\DSL` is organised by phase: `DSL/Lexing` (source text to tokens),
`DSL/Parsing` (tokens to an AST, with `ASTNodes/`, `Writing/`, `Validation/`), and
`DSL/Compiling` (AST to SQL, with `WhereClause/`). The two contracts a schema
satisfies — `SchemaVocabulary` for validation and `ConditionResolver` for
compilation — sit at the `DSL` root, since each is needed by a different phase.

The rule model itself is *not* under `DSL`, because it is not specific to the
string syntax: `Warrant\Rules` holds the resolved rule structures plus rule
resolution (`RuleResolver` and `RuleResolutionContext`), and `Warrant\Builders`
holds the fluent builders. The DSL depends on `Warrant\Rules` — parsing produces
those structures and compiling consumes them — not the other way round.

`RuleResolver` is the one every application implements, so this is the row most
likely to affect you — update the `implements` clause and the import; the interface
itself is unchanged.

**The rule syntax itself is unchanged.** This is a namespace move only; no rule
string, resolver return value, or `.warrant` file needs editing.

### What you get in return

Registering a schema is now a string-to-string entry. A schema class and its model
are loaded the first time that schema is used, so cost scales with the schemas a
request actually touches rather than with the number registered.

### Errors you may see on first run

These are deliberately raised the first time a schema is resolved rather than at
boot, because checking any of them requires loading the class:

- `Schema key [...] is registered to [...], which is not a Warrant\Schema\WarrantSchema.`
- `Schema [...] names model [...], but that model does not use the Warrant\HasWarrantSchema trait, ...`
- `Model [...] must declare warrantSchema() as `public static`.`
- `Schema [...] names model [...], but that model names schema [...]; a schema and its model must name each other.`

Registering one schema under two keys throws when the index is built:

- `Schema [...] is registered under more than one schema key [...]`
