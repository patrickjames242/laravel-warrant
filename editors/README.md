# Editor tooling for the Warrant DSL

Syntax highlighting for the Warrant authorization rule language, in the editors
where it is written, and the clients that start its language server. The grammar lives here alongside the parser that defines
the language, so the two stay in sync.

This directory is excluded from the published Composer package
(`/editors export-ignore` in `.gitattributes`); it is developer/editor tooling,
not part of the runtime library.

## Contents

| Path | What it is |
|------|------------|
| `vscode/` | The VS Code extension: grammar, PHP injection, and the client that starts the language server. Publishable to the VS Code Marketplace. |
| `vscode/syntaxes/warrant.tmLanguage.json` | **The canonical TextMate grammar.** Shared by every editor that speaks TextMate. |
| `phpstorm/` | The PhpStorm plugin: highlighting, PHP injection, and the client that starts the language server. |

## One grammar, three editors

`vscode/syntaxes/warrant.tmLanguage.json` is a TextMate grammar — a format VS
Code, PhpStorm, and Sublime all understand:

- **VS Code** — bundled by the extension in `vscode/` (see `vscode/README.md`).
- **PhpStorm** — the plugin in `phpstorm/` has a native lexer mirroring the same
  token rules, which PSI injection into PHP needs (see `phpstorm/README.md`).
- **Sublime** — drop the grammar in `Packages/User`.

So the token rules are written down twice in this directory, in the grammar and
in PhpStorm's lexer, and a change to the DSL means updating both. Everything
beyond colouring comes from the language server, so no editor needs a third
copy.

## What triggers highlighting

- **Standalone `.warrant` files** — matched by file extension, everywhere.
- **Inside PHP** — a heredoc/nowdoc labelled `WARRANT`:
  ```php
  <<<'WARRANT'
      if is_self they can edit
      WARRANT
  ```
  Works in VS Code and PhpStorm.
- **Inside PHP, where rule text is expected** — VS Code and PhpStorm only. A
  string literal, or a heredoc/nowdoc of any label, is highlighted when it is the
  first argument of `warrant()`, `Warrant::parse()`, `WarrantSyntax::parse()`,
  `WarrantParser::parse()`, or a builder's raw methods, `->ifRaw()` to `->orIfNotRaw()`:
  ```php
  warrant('is_owner or in_team(:team)', ['team' => $team]);
  ```
  Both also highlight a string returned from a `#[DerivedCondition]` or
  `#[RuleTemplate]` method, alone or inside a returned array, and from `rules()`.
  PhpStorm checks that `rules()` is on a schema or rule provider and highlights
  an array it returns; a TextMate grammar cannot see the class, so VS Code
  highlights only a string returned from `rules()`, leaving the arrays of a
  Laravel form request as PHP.

## The language server

Diagnostics, hover, completion, go-to-definition, quick fixes and an outline come
from one language server, in the package itself: `bin/warrant-language-server`,
installed as `vendor/bin/warrant-language-server`, with its code in
`src/LanguageServer/`. It reuses the package's own parser and validator, so it
understands the language exactly as Warrant does.

The clients here are thin. Each finds the script in the project, runs it as
`php <script>`, and sends it whole `.warrant` and `.php` files. The server finds
the rule text in a PHP file itself, and answers in the PHP file's own positions,
so no client maps positions.

| Client | Where |
|------|------|
| VS Code | `vscode/src/extension.ts` |
| PhpStorm | `phpstorm/src/main/kotlin/com/warrant/phpstorm/lsp/` (2024.2 and later) |

Any other editor with an LSP client can run `php vendor/bin/warrant-language-server`
from the project root. The docs page `docs/content/editors/language-server.md`
describes the server's features and settings.
