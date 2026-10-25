# Changelog

Changes to the `laravel-warrant` package. Releases up to v4.3.4 are described in
the [GitHub releases](https://github.com/patrickjames242/laravel-warrant/releases).
The VS Code extension keeps its own changelog in `editors/vscode/CHANGELOG.md`.

## Unreleased

### Changed

- The schema classes are grouped by feature under `Warrant\Schema`:
  `Abilities\` holds `Ability`, `DeclaresAbility`, `AbilityDefinition` and
  `StandardAbilities`; `Conditions\` holds the `#[RowCondition]`,
  `#[GlobalCondition]` and `#[DerivedCondition]` attributes beside their
  contexts, `ConditionDefinition` and `ConditionKind`; `RuleTemplates\` holds
  `RuleTemplate`, `RuleTemplateContext` and `RuleTemplateDefinition`; and
  `Denials\` holds `WarrantDenialContext` and `WarrantUngrantedContext`.
  `WarrantSchema` and `RequiredContext` stay in `Warrant\Schema`.
- Messages, docs and names say "can/cannot clause" where they said only
  "clause". The `missing-clause` diagnostic is now
  `missing-can-or-cannot-clause` (`DiagnosticCode::MissingCanOrCannotClause`),
  and `RuleNode::hasGenericClause()` is now
  `hasGenericCanOrCannotClause()`.
- `WarrantRuleNode` is now `RuleNode`.
- The syntax node interfaces drop their `I`: `INode`, `IBooleanExpressionNode`,
  `IRuleEntryNode` and `ISchemaScopedNode` are now `Node`,
  `BooleanExpressionNode`, `RuleEntryNode` and `SchemaScopedNode`.

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
