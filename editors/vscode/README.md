# Warrant DSL — VS Code

Syntax highlighting for the [Warrant](https://github.com/patrickjames242/laravel-warrant)
authorization rule language.

## Features

- Highlighting for standalone `.warrant` files.
- Highlighting **inside PHP** for heredocs/nowdocs labelled `WARRANT`:

  ```php
  $rules = WarrantRuleSet::fromSyntax('timesheets', <<<'WARRANT'
      # only the author may edit their own draft
      if is_self
      they can edit, view
      they cannot approve
      WARRANT);
  ```

  The label is the trigger — name the heredoc `WARRANT` and the body lights up.
  Any other label is treated as a plain PHP string.

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
