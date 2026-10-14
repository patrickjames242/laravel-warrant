# Warrant DSL — VS Code

Language support for the [Warrant](https://github.com/patrickjames242/laravel-warrant)
authorization rule language: syntax highlighting, and the Warrant language
server's diagnostics, hover, completion and navigation.

## Language server

In a project that has laravel-warrant installed, the extension starts the
Warrant language server for `.warrant` files and for PHP files. The server finds
the Warrant inside a PHP file itself — the same heredocs, nowdocs and rule-text
arguments that are highlighted — and offers:

- **Diagnostics** for mistakes in rule text, as you type.
- **Hover** information on the names in a rule.
- **Completion**, triggered as you type and on space, `(`, `@`, `.` and `,`.
- **Go to definition** from a name in a rule to where it is defined.
- **Outline** (document symbols) of the rules in a file.
- **Quick fixes** for the mistakes the server knows how to repair.

### Requirements

- laravel-warrant installed in the project with Composer, so that
  `vendor/bin/warrant-language-server` exists.
- PHP 8.2 or later on your `PATH`, or `warrant.languageServer.phpPath` pointing at
  it. It need not be the PHP your project runs on.

The extension looks for the server script, relative to the first workspace
folder, in this order and uses the first that exists:

1. `warrant.languageServer.path`, when set
2. `vendor/bin/warrant-language-server`
3. `vendor/patrickhanna/laravel-warrant/bin/warrant-language-server`
4. `bin/warrant-language-server` (the laravel-warrant repository itself)

It always runs the script as `php <script>`. When no script is found the server
is not started, and the reason is logged in the **Warrant Language Server**
output channel.

### Settings

| Setting | Default | Description |
| --- | --- | --- |
| `warrant.languageServer.enabled` | `true` | Start the language server. |
| `warrant.languageServer.path` | `""` | Path to the server script, absolute or relative to the workspace root. |
| `warrant.languageServer.phpPath` | `"php"` | The PHP executable that runs the server. |
| `warrant.vocabulary` | `"auto"` | How the server learns your schemas: `auto` reads your PHP files and also boots the app in a short-lived child process for exact results; `static` only reads files and never runs project code; `off` checks syntax only. |

Changing any `warrant.*` setting restarts the server. The
**Warrant: Restart Language Server** command restarts it by hand.

### Workspace trust

The language server runs PHP from your project, and with `warrant.vocabulary`
set to `auto` it boots your application. In an untrusted workspace the server is
not started and only syntax highlighting is available; trusting the workspace
starts it.

## Highlighting

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

- Highlighting **inside PHP** for a string returned from a method marked
  `#[RuleTemplate]` or `#[DerivedCondition]`, alone or inside a returned array,
  and for a string returned from `rules()`:

  ```php
  #[RuleTemplate]
  public function lockedOnceApproved(): string
  {
      return "
          if is_approved
          they cannot
          because 'This pay run has been approved and can no longer be changed.'
      ";
  }
  ```

  An array returned from `rules()` is left as PHP, since that is also where a
  Laravel form request returns its validation rules.

### Highlighted tokens

Keywords (`if they can cannot and or not`), single- and double-quoted string
literals with `\'` / `\"` / `\\` escapes, numbers, `true`/`false`/`null`,
`@context`, `:named` bindings,
positional `?`, the `*` wildcard, and `#` line comments.

### Typing

`(`, `{`, `'` and `"` close as you type them, in `.warrant` files and in the
Warrant inside PHP, and Toggle Line Comment uses `#`. Inside PHP this comes from
the injection grammars' `embeddedLanguages`, which tell VS Code the text there is
Warrant, so `language-configuration.json` applies to it.

## Building

```bash
npm install
npm run compile       # or: npm run watch
npm run lint
```

## Publishing

```bash
npx @vscode/vsce package   # -> warrant-dsl-<version>.vsix (share or drag-install)
npx @vscode/vsce login <publisher>
npx @vscode/vsce publish   # publish to the Marketplace
```

## Local install without publishing

```bash
npx @vscode/vsce package
code --install-extension warrant-dsl-<version>.vsix
```
