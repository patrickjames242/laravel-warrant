# Warrant DSL — VS Code

Syntax highlighting for the [Warrant](https://github.com/patrickjames242/laravel-warrant)
authorization rule language.

## Features

- Highlighting for standalone `.warrant` files.
- Highlighting **inside PHP** for heredocs/nowdocs labelled `WARRANT`, wherever
  they stand:

  ```php
  $rules = Warrant::parse(<<<'WARRANT'
      # only the author may edit their own draft
      for timesheets {
          if is_self they can edit, view
      }
      WARRANT);
  ```

- Highlighting **inside PHP** for the rule-text argument of `warrant()`,
  `Warrant::parse()`, `WarrantSyntax::parse()`, `WarrantParser::parse()` and the
  builders' `->ifRaw()` / `->orIfRaw()` — a single- or double-quoted string, or a
  heredoc/nowdoc of any label, passed first or by name:

  ```php
  warrant('is_owner or in_team(:team)', ['team' => $team]);
  Warrant::rule()->ifRaw('is_owner or is_admin')->theyCan('view');
  ```

  A variable or a concatenation is left as PHP.

## Highlighted tokens

Keywords (`if they can cannot and or not`), single- and double-quoted string
literals with `\'` / `\"` / `\\` escapes, numbers, `true`/`false`/`null`,
`@context`, `:named` bindings,
positional `?`, the `*` wildcard, and `#` line comments.

## Before publishing

Set `publisher` in `package.json` to your VS Code Marketplace publisher id
(it is currently `REPLACE_WITH_YOUR_PUBLISHER_ID`).

## Publishing

```bash
npm i -g @vscode/vsce
vsce package          # -> warrant-dsl-<version>.vsix (share or drag-install)
vsce login <publisher>
vsce publish          # publish to the Marketplace
```

## Local install without publishing

```bash
vsce package
code --install-extension warrant-dsl-<version>.vsix
```
