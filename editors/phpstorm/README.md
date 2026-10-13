# Laravel Warrant — PhpStorm plugin

Syntax highlighting for the Warrant rule DSL, both in standalone `.warrant`
files and inside PHP: heredocs/nowdocs labelled `WARRANT`, and strings wherever
rule text is expected (see `WarrantInjector`). It also starts the Warrant
language server for errors, hover, completion, go-to-definition and quick fixes
in `.warrant` files and in the Warrant inside PHP files.

This is the PhpStorm counterpart of the VSCode extension in `../vscode`. The
lexer here mirrors the token rules of `../../src/DSL/Lexing/Lexer.php` — keep
them in sync.

## What's here

| File | Role |
|------|------|
| `WarrantLanguage` / `WarrantFileType` | Registers Warrant as a language + the `.warrant` extension |
| `WarrantLexer` | Tokenizes Warrant source (mirror of `Lexer.php`, but tolerant) |
| `WarrantSyntaxHighlighter(Factory)` | Maps tokens → theme colors (produces the highlighting) |
| `WarrantParser` / `WarrantParserDefinition` / `WarrantFile` | A deliberately flat parse tree — the minimum injection requires |
| `WarrantInjector` | Injects Warrant into PHP strings that hold rule text: `<<<WARRANT` heredocs, `warrant('...')`, `Warrant::parse('...')`, returns from `rules()`, … |
| `lsp/WarrantLspServerSupportProvider` / `lsp/WarrantLspServerDescriptor` | Starts the language server and tells the IDE which files it serves |
| `lsp/WarrantServerLaunch` | Finds the server script and the PHP binary to run it with |
| `lsp/WarrantSettings` / `lsp/WarrantSettingsConfigurable` | The project settings and their page |
| `lsp/RestartWarrantLanguageServerAction` | **Tools → Restart Warrant Language Server** |

The parser is intentionally trivial. Error-checking and autocomplete come from
the Warrant language server (which reuses the PHP parser/validator), not from a
native IntelliJ grammar — so this file stays flat.

## Language server

The plugin runs the language server that ships with the
`patrickhanna/laravel-warrant` Composer package, speaking LSP over stdio. It
needs a PhpStorm that ships the IntelliJ Platform LSP API — 2024.2 or later.
The client is declared in `META-INF/warrant-lsp.xml` and loads only when that
API is present (an optional dependency on `com.intellij.modules.ultimate`);
highlighting and injection work either way.

The server starts when a `.warrant` or PHP file opens and the project has a
server script. The first of these that exists, relative to the project base
directory, is run as `<php> <script>`:

1. the **Server path** setting, if set (absolute or relative to the project);
2. `vendor/bin/warrant-language-server`;
3. `vendor/patrickhanna/laravel-warrant/bin/warrant-language-server`;
4. `bin/warrant-language-server` (the laravel-warrant repository itself).

If none exists, no server starts. The script is always passed to PHP, because
the `vendor/bin` entry is a Composer proxy script.

PHP files are sent to the server whole. The server finds the Warrant in them
itself and answers in the PHP file's own coordinates, so diagnostics and the
rest appear directly on PHP files; for any position outside Warrant text it
answers nothing, leaving PhpStorm's own PHP features as they are. Completion
asks the server only inside `.warrant` files, injected Warrant and PHP strings.
The server watches `**/*.php` (through `workspace/didChangeWatchedFiles`) to
re-read schemas when they change.

### Settings

**Settings → Languages & Frameworks → Warrant**, stored per project in
`.idea/warrant.xml`. Applying a change restarts the server.

| Setting | Default | Meaning |
|---------|---------|---------|
| Enable language server | on | Whether the server starts at all |
| PHP executable | `php` | The PHP binary the server runs under. With `php`, the one on the PATH is used, or the project's local PHP CLI interpreter when the PATH has none |
| Server path | empty | The server script; empty means the search above |
| Vocabulary | `auto` | Sent as `initializationOptions.vocabulary`. `auto` reads PHP files and also boots the app in a short-lived child process; `static` reads PHP files and never runs project code; `off` checks syntax only |

**Tools → Restart Warrant Language Server** restarts it by hand, and says why
when no server can start. The language-services widget in the status bar also
shows the server, with a link to the settings page.

## Prerequisites

- JDK 21. A newer JDK on the PATH may be refused by the Kotlin/Gradle
  toolchain; point `JAVA_HOME` at a JDK 21, such as the JBR inside a PhpStorm
  2024.2 install.
- The Gradle wrapper (`./gradlew`). If it's missing, generate it once with a
  system Gradle: `gradle wrapper --gradle-version 8.10`. Or just open this
  folder in IntelliJ IDEA, which imports and provisions everything.

## Develop: run a sandbox IDE

```bash
./gradlew runIde
```

Launches a separate, sandboxed PhpStorm with the plugin loaded. Open a PHP file,
write a heredoc, and it should highlight:

```php
$rule = <<<'WARRANT'
if can(view for pay_periods(@context pay_period_id))
they can approve
WARRANT;
```

Edit code, stop, re-run. This never touches your real IDE.

## Install into your real PhpStorm

```bash
./gradlew buildPlugin
```

Produces `build/distributions/warrant-phpstorm-<version>.zip`, where the
version is the one set in `build.gradle.kts`. Then in PhpStorm:

**Settings → Plugins → ⚙ → Install Plugin from Disk…** → select that zip →
**Restart IDE**.

It then appears in the Plugins list like any installed plugin (disable/uninstall
from there). To update, bump `version` in `build.gradle.kts`, rebuild, and
install the new zip the same way.

## Verify compatibility

```bash
./gradlew verifyPlugin
./gradlew verifyPlugin -PverifyLocalIde=/Applications/PhpStorm.app
```

Runs the IntelliJ Plugin Verifier against PhpStorm 2024.2.4 and, with
`-PverifyLocalIde`, against a locally installed IDE too.

## Notes / gotchas

- Versions in `build.gradle.kts` (`phpstorm("2024.2.4")`, the Gradle plugin
  version) may need bumping over time; IntelliJ will point you at valid values.
- The plugin `<depends>` on `com.jetbrains.php`, so it loads only in PhpStorm /
  IDEs with PHP support — which is the point.
- The heredoc label is configured in `WarrantInjector.LABEL` (`WARRANT`). The
  calls, attributes and types that mark a string as rule text are the sets at
  the top of `WarrantInjector`'s companion object.
