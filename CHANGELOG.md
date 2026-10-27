# Changelog

Changes to the `laravel-warrant` package. Releases up to v4.3.4 are described in
the [GitHub releases](https://github.com/patrickjames242/laravel-warrant/releases).
The VS Code extension keeps its own changelog in `editors/vscode/CHANGELOG.md`.

## Unreleased

### Added

- `php artisan warrant:format` formats rule text, taking the same arguments and
  options as `vendor/bin/warrant-format`.
- Both take glob patterns, with `**` for any number of directories and `{a,b}`
  for alternatives, beside files and directories, and `--diff` to show what
  would change. `--ignore-unparsable` reports rule text that does not parse
  without failing.
- The formatter formats rule text in a quoted PHP string written over several
  lines, keeping the string's own indentation. A quoted string on one line is
  still formatted on one line.

### Changed

- Rule text that does not parse, or a path that matches nothing, makes the exit
  code 2, apart from the 1 that `--check` gives for a file that would change.

### Fixed

- Rule text returned from `rules()` is only a string returned alone or as an
  item of a returned list. A string under an array key, at any depth, is no
  longer read as rule text, so the validation rules a Laravel form request
  returns from its `rules()` are neither checked by the language server nor
  formatted.

## 4.7.1 — 2026-10-27

### Changed

- The formatter puts a rule's clause on the line after its condition whenever
  the condition is more than one plain condition: a chain of `and` or `or`, a
  `can(...)` with a `for`, or a `check(...)`, however short the rule. A rule
  with one plain condition still stays on one line when it fits. `toSyntax()`
  and `toBoundSyntax()` write rules the same way.

### Fixed

- The formatter breaks an `@include`'s `for` list one ability to a line when it
  does not fit, as it does a clause's, instead of leaving it to overflow. The
  `for` stays on the `@include` line.

## 4.7.0 — 2026-10-27

### Added

- A formatter for rule text, as strict as Prettier, with nothing to configure.
  `Warrant\DSL\Formatting\WarrantFormatter` formats text, refusing text with a
  syntax error and checking every result says what the original said and keeps
  its comments.
- `vendor/bin/warrant-format` formats `.warrant` files and the rule text in PHP
  files, in place or, with `--check`, listing what would change. With
  `--stdin-filepath`, it formats standard input for an editor.
- The language server formats a `.warrant` file, and the rule text in a PHP file
  as the `source.format.warrant` code action.
- The language server completes a condition or template that takes arguments,
  and a schema with rows in a `can(...)` or `check(...)` handle, with its
  parentheses open and the cursor inside, in an editor that takes snippets.

### Changed

- `toSyntax()` and `toBoundSyntax()` write rule text in the formatter's house
  style: the tree is written on one line and laid out by `WarrantFormatter`.

## 4.6.1 — 2026-10-26

### Fixed

- The language server completes a derived condition's answer as a condition:
  it offers conditions, `can(`, `check(` and `not` at its start, and only `and`
  and `or` after an operand, where it offered the words that start a rule.

## 4.6.0 — 2026-10-26

### Changed

- A condition or rule template given more arguments than its method has
  parameters for is refused, as one given too few is: by validation, with the
  new `too-many-arguments` diagnostic, and by expansion and the compiler for a
  rule that skipped validation. A method whose last parameter is variadic takes
  any number. Extra arguments no longer pass through to `$c->arguments`; a
  condition that reads its arguments from there declares a variadic parameter.
- `ConditionDefinition` and `RuleTemplateDefinition` carry `maxArgumentCount`,
  null for a variadic method, and the language server reads it from source and
  from the running application.

## 4.5.0 — 2026-10-26

### Added

- `Warrant\Schema\Context\ContextPolicy` merges a schema's default context and
  enforces its required context keys, from what the schema declares.
- `Warrant\Schema\Vocabulary` holds what rule text may say about one schema, as
  data: its abilities, conditions and templates by name, and how a row is found.
  `Vocabulary::of($schema)` builds one.
- `SchemaRegistry::resolveOrFail()` and `resolveOrNull()` answer with a
  `ResolvedSchema`: the schema instance, built once and kept, and the key it is
  registered under. `WarrantGuardForSchema::schemaKey()` returns a guard's key.
- A query schema's key may span several columns without a `matchKey()`:
  `key()` returns the columns, and a row is named by one argument per column.
- Hover in the language server says where a schema's rows come from.
- Global schemas. A class extending `GlobalSchema` and listed under `global` in
  `config/warrant.php` declares global conditions, derived conditions and rule
  templates any schema's rules may use by name. A name is looked up in the
  schema its text was written in, then in each global in the order listed; text
  a global answers with is looked up in the globals alone. A global schema has
  no key, so no rule names it. No two globals may declare the same condition or
  template: the first lookup that reaches the globals throws, and the language
  server marks the later declaration (`duplicate-global-name`).
- `Warrant\DSL\Resolution\ConstructLookup` says what a condition or template
  name means: `condition()` and `ruleTemplate()` answer with the definition and
  the schema that declares it, as an `IFoundConstruct` whose `schema()` loads
  that schema. `ISchemaId` names a schema: `KeyedSchemaId` by its key,
  `GlobalSchemaId` by its class.
- `ISchemaCatalog::globalClasses()`, `findGlobal()` and `constructs()`. The
  `SchemaRegistry` is the application's catalog: it reads each schema's
  vocabulary once and keeps one `ConstructLookup`.
- A rule template's body may sit under a `for` header naming its own schema, so
  an editor knows whose names it uses when the body lives in a file of its own.
- The diagnostics `header-names-another-schema`, for a template's or derived
  condition's answer under a header naming another schema, and
  `header-in-global-answer`, for a global's answer under any header.
- The language server reads the global schemas, from source and from the
  running application. It checks text returned from a `#[RuleTemplate]` or
  `#[DerivedCondition]` method as that method's answer, and hover,
  go-to-definition, completion and quick fixes find a global's conditions and
  templates where they are declared.

### Removed

- `!` is no longer a synonym for `not`. A rule writing it is rejected as an
  unexpected character; write `not` instead.
- `WarrantSchema`, replaced by the schema classes below.
- `RegisteredSchemas`. The `SchemaRegistry` is the catalog;
  `Warrant::registry()` stands where `new RegisteredSchemas` did.
- `SchemaVocabulary` and `ConditionResolver`. Validation reads a `Vocabulary`
  through `ISchemaCatalog`, and the compiler reads a schema through its
  interfaces.
- A query schema that cannot name a row. One with neither `key()` nor
  `matchKey()` is refused when it is first constructed, and the `unnameable-row`
  diagnostic is gone.
- `schemaKey()` on a schema, and the static `abilityNames()`,
  `abilityDefinitions()`, `conditionKeys()`, `rowConditionKeys()`,
  `globalConditionKeys()`, `derivedConditionKeys()`, `ruleTemplateKeys()`,
  `hasRows()`, `hasRowKey()`, `keyDefinition()`, `requiredContextKeys()` and
  `partitionAbilitiesByContext()`. Ask the registry for a schema's key, and a
  schema instance for the rest.

### Changed

- A schema extends one of three classes, by where its rows come from, all in
  `Warrant\Schema\Types\Classes`:
  - `ModelSchema`, rows from a model's table, named by `const model` as before;
  - `QuerySchema`, rows from a query: `virtualTable()` is now an instance
    `rows()`, and `const key` is now `key()`;
  - `RowlessSchema`, no rows, in place of a schema with `const model = ''`.
- The engine reads a schema through interfaces in
  `Warrant\Schema\Types\Interfaces`: `ISchema`, `IAbilitySchema`, `IRowSchema`
  and `IModelSchema`, each combining one `IProvides…` interface per construct. A
  schema in `config/warrant.php` must implement `ISchema`, and a check is made
  only against an `IAbilitySchema`.
- A schema is read through instance methods: `abilities()`, `abilityNames()`,
  `ability()`, `conditions()`, `condition()`, `rowConditions()`,
  `globalConditions()`, `derivedConditions()`, `ruleTemplates()`,
  `ruleTemplate()`, `requiredContextKeys()` and `rowMatcher()`.
- `defaultContext()` is public.
- A query schema's rows are named `warrant_rows` in SQL, in place of the schema
  key, unless a hop names them.
- `RowConditionContext::$keyColumn` is now `$keyColumns`, a list. `column()` with
  no argument needs a key of exactly one column.
- The schema classes are grouped by feature under `Warrant\Schema`, each beside
  its `IProvides…` interface and the trait that reads it: `Abilities\`;
  `Conditions\`, with `Row\`, `Global\` and `Derived\` holding each kind's
  attribute and context; `Context\`, holding `RequiredContext`;
  `Denials\`; `Rows\`; `Rules\`; and `RuleTemplates\`.
- Messages, docs and names say "can/cannot clause" where they said only
  "clause". The `missing-clause` diagnostic is now
  `missing-can-or-cannot-clause` (`DiagnosticCode::MissingCanOrCannotClause`),
  and `RuleNode::hasGenericClause()` is now
  `hasGenericCanOrCannotClause()`.
- `WarrantRuleNode` is now `RuleNode`.
- A `for` header only says whose names a text uses. Whether its rules name their
  abilities comes from what produced the text, header or none: a schema's rules
  and a rule provider's name them, a template's leave them off, and text nothing
  has placed may do either, so long as all its rules agree.
- An unknown condition or template is reported as declared by neither the
  schema nor any global schema, or, in a global's answer, by no global schema.
  The compiler says the same as validation, in place of `Condition [x] is not
  defined on schema [Class]`.
- A can/cannot clause or `@include` naming no abilities where it must is
  reported as `the rules for [x] hold one that names none`, header or none.
- `Vocabulary` takes the class of the schema it was read from as its first
  argument, `$schemaClass`.
- Every interface's name starts with `I`, so it can't be mistaken for a class:
  `DeclaresAbility`, `RuleProvider`, `SchemaCatalog` and `CompilationUnit` are
  now `IDeclaresAbility`, `IRuleProvider`, `ISchemaCatalog` and
  `ICompilationUnit`. The syntax node interfaces keep theirs: `INode`,
  `IBooleanExpressionNode`, `IRuleEntryNode` and `ISchemaScopedNode`.

## 4.4.0 — 2026-10-21

### Added

- A rule template can be told the abilities its `@include` supplies: take a
  `RuleTemplateContext` as the first parameter and read `$context->abilities`.
  It comes from the include's `for` list, the ability block around it, or the
  include that expanded the template it sits in.
- `#[RuleTemplate(callOncePerAbility: true)]` calls a template once for each
  ability, each call's rules taking that ability alone, and
  `$context->ability()` returns which one. `ability()` throws for a template
  not declared `callOncePerAbility`, even when its include names one ability.
- Rule text a template or derived condition returns as a string may write the
  method's parameters as bindings, by name as declared or in snake case:
  `:parentRelation` or `:parent_relation`.
- A binding may stand for abilities wherever one is written, its value an
  ability name or a list of them: `if is_editor they can view, :editing`.
- A `can` that names only an ability may leave off its parentheses:
  `if can comment they can share`.
- `Build` is the entry point for building rules in code, with `Build::rule()`,
  `Build::condition()`, `Build::ruleSet()`, fluent `Build::can()` and
  `Build::check()` references, includes, every raw variant (`ifRaw()` to
  `orIfNotRaw()`) and `when()` branches.
- The `warrant()` and `warrant_file()` helpers return a `BoundSyntax`, rule text
  with its bindings, which `Warrant::parse()`, `WarrantSyntax::parse()` and a
  provider's `rules()` accept.
- Every syntax node carries a `location`: the source it was written in, where in
  that source, and what produced it (a rule provider, a schema's `rules()`, a
  template, a derived condition, or a file open in an editor). Errors point at the node that caused them,
  including inside a template's body.
- `$guard->expandedRuleSet()` returns an `ExpandedSyntax`, the validated and
  expanded rules every check compiles, each rule saying where it was written.

- When blocks: `when <condition> { ... } otherwise { ... }` says a condition once
  over the rules in its body, and its negation over the rules in its
  `otherwise`. Expansion joins the condition to each rule with `and`, so a block
  decides every check exactly as the rules written out in full. Both bodies are
  always braced, there is no `otherwise when`, and an unknown condition leaves
  both bodies without effect. `when` and `otherwise` are reserved words. The VS
  Code (0.13.0) and PhpStorm (0.8.0) extensions and the language server know
  them.

### Changed

- Rule text is handled in fixed stages: validate, put binding values in place,
  then expand. Only a validated `ValidSyntax` hands out nodes, so `->rule()`,
  `->scopedTo()` and the like validate the text first.
  `WarrantSyntax::validateWithoutSchemas()` checks text that names no schema.
- A row condition names its columns with `$c->column()`, which replaces
  `$c->row()`.
- `Warrant::parse()` takes rule text or a `BoundSyntax`; bindings are passed
  through `warrant()`: `Warrant::parse(warrant($text, ['team' => $team]))`.
- `theyCannotBecause()` takes a generic denial's message by name,
  `theyCannotBecause(message: '...')`, and throws a `LogicException` when called
  without one, so a lone ability is never read as a message.
- `rules()` on a rule provider and a schema is declared `mixed`.
- A rule names each ability once among what it grants and once among what it
  denies, and `*` stands alone: `they can view, view` and `they can *, view` are
  errors.
- A bound ability is validated: its value must be an ability name, or a
  non-empty list of them in an ability list, and is checked against the schema.
- A `with` map naming a key twice is rejected, and so is a row selector bound to
  `null`.

### Removed

- Positional `?` bindings. Every binding is named.
- `Warrant::validate()`. Use `WarrantSyntax::validate()`.
- `Warrant::rule()` and `Warrant::condition()`. Use `Build::rule()` and
  `Build::condition()`.
- `ExpandedRuleSet`, replaced by `ExpandedSyntax`.

### Docs

- Usage Examples pages; the tutorial is renamed Basic Tutorial and writes its
  rules in a `.warrant` file; the Core concepts section is gone.
- Rule text in the docs is highlighted as the VS Code and PhpStorm extensions
  highlight it.
- New sections on building rules through `Build`, abilities from bindings, each
  ability written once, template and derived condition parameters as bindings,
  and telling a template its abilities.
- A When blocks page under Rules, and when blocks in the grammar reference, the
  cheat sheet and the expansion steps.
