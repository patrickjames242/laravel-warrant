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
| `vscode/syntaxes/warrant.tmLanguage.json` | **The canonical TextMate grammar.** Shared by every editor that speaks TextMate. |
| `phpstorm/` | How to use the grammar in PhpStorm/IntelliJ today (TextMate bundle import). |
| `zed/` | The Zed extension (language config + tree-sitter queries). Publishable to Zed's extension registry. |
| `tree-sitter-warrant/` | The tree-sitter grammar Zed needs, since Zed cannot read TextMate. |

## Two grammars, four editors

`vscode/syntaxes/warrant.tmLanguage.json` is a TextMate grammar — a format VS
Code, PhpStorm, and Sublime all understand. For those three, everything else
here just points the editor at it:

- **VS Code** — bundled by the extension in `vscode/` (see `vscode/README.md`).
- **PhpStorm** — imported as a TextMate bundle (see `phpstorm/README.md`).
- **Sublime** — drop the grammar in `Packages/User`.

**Zed is the exception.** It has no TextMate support whatsoever; every language
it highlights is defined by a tree-sitter grammar — a real generated parser —
plus queries mapping the syntax tree to theme colors. That grammar lives in
`tree-sitter-warrant/` and the extension around it in `zed/` (see
`zed/README.md`). Its highlight scopes deliberately mirror the TextMate ones so
a rule looks the same in every editor.

So the language is now written down twice in this directory, and a change to the
DSL means updating both. `specs/language-server.md` is the plan for stopping
that multiplication from growing any further.

## What triggers highlighting

- **Standalone `.warrant` files** — matched by file extension, everywhere.
- **Inside PHP** — a heredoc/nowdoc labelled `WARRANT`:
  ```php
  <<<'WARRANT'
      if is_self they can edit
      WARRANT
  ```
  Works automatically in VS Code, and in Zed — Zed's PHP extension injects
  whatever language a heredoc's closing label names. In PhpStorm it needs
  Language Injection (see `phpstorm/README.md`).

## Roadmap

Highlighting is step one. Next is a **language server** (reusing this repo's PHP
parser) for live syntax-error diagnostics, hover, and completion of condition /
ability names — shared by a VS Code extension, a native PhpStorm plugin, and
Zed (which speaks LSP natively, so the `zed/` extension gains a Rust shim and
a `[language_servers.warrant]` entry).
