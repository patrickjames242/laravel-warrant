# Laravel Warrant — PhpStorm plugin

Syntax highlighting for the Warrant rule DSL, both in standalone `.warrant`
files and inside PHP heredocs/nowdocs labelled `WARRANT` (or `DSL`).

This is the PhpStorm counterpart of the VSCode extension in `../vscode`. The
lexer here mirrors the token rules of
`../../src/RuleSyntaxTree/Parsing/Lexer.php` — keep them in sync.

## What's here

| File | Role |
|------|------|
| `WarrantLanguage` / `WarrantFileType` | Registers Warrant as a language + the `.warrant` extension |
| `WarrantLexer` | Tokenizes Warrant source (mirror of `Lexer.php`, but tolerant) |
| `WarrantSyntaxHighlighter(Factory)` | Maps tokens → theme colors (produces the highlighting) |
| `WarrantParser` / `WarrantParserDefinition` / `WarrantFile` | A deliberately flat parse tree — the minimum injection requires |
| `WarrantInjector` | Injects Warrant into `<<<WARRANT` PHP heredocs |

The parser is intentionally trivial. Error-checking and autocomplete are meant
to come later from a Warrant language server (reusing the PHP parser/validator),
not from a native IntelliJ grammar — so this file stays flat.

## Prerequisites

- JDK 21
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

Produces `build/distributions/warrant-phpstorm-0.1.0.zip`. Then in PhpStorm:

**Settings → Plugins → ⚙ → Install Plugin from Disk…** → select that zip →
**Restart IDE**.

It then appears in the Plugins list like any installed plugin (disable/uninstall
from there). To update, bump `version` in `build.gradle.kts`, rebuild, and
install the new zip the same way.

## Notes / gotchas

- Versions in `build.gradle.kts` (`phpstorm("2024.2.4")`, the Gradle plugin
  version) may need bumping over time; IntelliJ will point you at valid values.
- The plugin `<depends>` on `com.jetbrains.php`, so it loads only in PhpStorm /
  IDEs with PHP support — which is the point.
- The heredoc label is configured in `WarrantInjector.LABEL` (`WARRANT`). Note
  the test suite currently uses `<<<'DSL'`, so those heredocs won't inject until
  they're relabelled `WARRANT`.
