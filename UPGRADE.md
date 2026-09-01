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
