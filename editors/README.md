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
| `zed/` | The Zed extension (language config + tree-sitter queries). Publishable to Zed's extension registry. |
| `tree-sitter-warrant/` | The tree-sitter grammar Zed needs, since Zed cannot read TextMate. |

## Two grammars, four editors

`vscode/syntaxes/warrant.tmLanguage.json` is a TextMate grammar — a format VS
Code, PhpStorm, and Sublime all understand:

- **VS Code** — bundled by the extension in `vscode/` (see `vscode/README.md`).
- **PhpStorm** — the plugin in `phpstorm/` has a native lexer mirroring the same
  token rules, which PSI injection into PHP needs (see `phpstorm/README.md`).
- **Sublime** — drop the grammar in `Packages/User`.

**Zed is the exception.** It has no TextMate support whatsoever; every language
it highlights is defined by a tree-sitter grammar — a real generated parser —
plus queries mapping the syntax tree to theme colors. That grammar lives in
`tree-sitter-warrant/` and the extension around it in `zed/` (see
`zed/README.md`). Its highlight scopes deliberately mirror the TextMate ones so
a rule looks the same in every editor.

So the language is written down twice in this directory, and a change to the DSL
means updating both. Everything beyond colouring comes from the language server,
so no editor needs a third copy.

## What triggers highlighting

- **Standalone `.warrant` files** — matched by file extension, everywhere.
- **Inside PHP** — a heredoc/nowdoc labelled `WARRANT`:
  ```php
  <<<'WARRANT'
      if is_self they can edit
      WARRANT
  ```
  Works in VS Code, PhpStorm and Zed — Zed's PHP extension injects whatever
  language a heredoc's closing label names.
- **Inside PHP, where rule text is expected** — VS Code and PhpStorm only. A
  string literal, or a heredoc/nowdoc of any label, is highlighted when it is the
  first argument of `warrant()`, `Warrant::parse()`, `WarrantSyntax::parse()`,
  `WarrantParser::parse()`, or a builder's `->ifRaw()` / `->orIfRaw()`:
  ```php
  warrant('is_owner or in_team(:team)', ['team' => $team]);
  ```
  PhpStorm also highlights a string returned from `rules()` on a schema or rule
  provider, or from a `#[DerivedCondition]` or `#[RuleTemplate]` method. A
  TextMate grammar cannot see which method a `return` belongs to, so VS Code
  highlights those only when they are `WARRANT` heredocs. Zed's PHP extension
  owns the injections into PHP, so Zed has neither.
- **SQL inside `@sql "..."`** — Zed only, and only when a SQL extension is
  installed. The tree-sitter grammar can address a string's body as a node,
  which a TextMate grammar cannot, so the SQL is highlighted as SQL.

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
| Zed | Not yet: the `zed/` extension needs a Rust shim and a `[language_servers.warrant]` entry. |

Any other editor with an LSP client can run `php vendor/bin/warrant-language-server`
from the project root. The docs page `docs/content/editors/language-server.md`
describes the server's features and settings.
