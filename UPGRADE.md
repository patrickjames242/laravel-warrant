# Upgrade guide

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
