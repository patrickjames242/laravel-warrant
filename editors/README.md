# Editor tooling for the Warrant DSL

Syntax highlighting for the Warrant authorization rule language, in the editors
where it is written. The grammar lives here alongside the parser that defines
the language, so the two stay in sync.

This directory is excluded from the published Composer package
(`/editors export-ignore` in `.gitattributes`); it is developer/editor tooling,
not part of the runtime library.

## Contents

| Path | What it is |
|------|------------|
| `vscode/` | The VS Code extension (grammar + PHP heredoc injection). Publishable to the VS Code Marketplace. |
| `vscode/syntaxes/warrant.tmLanguage.json` | **The canonical grammar.** Shared by every editor. |
| `phpstorm/` | How to use the grammar in PhpStorm/IntelliJ today (TextMate bundle import). |

## The one grammar, three editors

`vscode/syntaxes/warrant.tmLanguage.json` is a TextMate grammar — a format VS
Code, PhpStorm, and Sublime all understand. Everything else here just points an
editor at it:

- **VS Code** — bundled by the extension in `vscode/` (see `vscode/README.md`).
- **PhpStorm** — imported as a TextMate bundle (see `phpstorm/README.md`).
- **Sublime** — drop the grammar in `Packages/User`.

## What triggers highlighting

- **Standalone `.warrant` files** — matched by file extension, everywhere.
- **Inside PHP** — a heredoc/nowdoc labelled `WARRANT`:
  ```php
  <<<'WARRANT'
      if is_self they can edit
      WARRANT
  ```
  Works automatically in VS Code. In PhpStorm it needs Language Injection
  (see `phpstorm/README.md`).

## Roadmap

Highlighting is step one. Next is a **language server** (reusing this repo's PHP
parser) for live syntax-error diagnostics, hover, and completion of condition /
ability names — shared by both a VS Code extension and a native PhpStorm plugin.
