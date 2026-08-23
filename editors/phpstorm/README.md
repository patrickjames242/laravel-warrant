# Warrant DSL — PhpStorm / IntelliJ

Until a native plugin ships, PhpStorm highlights the Warrant DSL by importing
the grammar as a **TextMate bundle**. It reuses the exact same grammar file as
the VS Code extension — there is no separate copy to keep in sync. JetBrains
IDEs can import a VS Code extension folder directly as a bundle.

## Install (per user, ~30 seconds)

1. **Settings → Editor → TextMate Bundles**
2. Click **+** and select the folder:
   ```
   editors/vscode
   ```
   (this repo's VS Code extension folder — its `package.json` tells the IDE
   about the `warrant` language and the `.tmLanguage.json` grammar)
3. **OK / Apply.**

Standalone `.warrant` files now highlight.

## Caveat: highlighting *inside* PHP heredocs

The TextMate import highlights standalone `.warrant` files, but it does **not**
light up rules embedded in PHP heredocs. PhpStorm handles PHP with its own
engine (not a TextMate grammar), so the VS Code injection does not apply here.

To get embedded highlighting in PhpStorm today, use **Language Injection**:

- Put a marker comment before the string —
  `WarrantParser::parse(/** @lang warrant */ '...')` — after registering the
  bundle above, or
- **Settings → Editor → Language Injections → +** and add a rule that injects
  the `warrant` language into the string argument of `fromSyntax()` /
  `WarrantParser::parse()`.

A future native plugin will do this automatically.

## Roadmap: native Marketplace plugin

The bundle is the get-started path. A proper one-click Marketplace plugin
(Custom Language API, Kotlin + Gradle) is planned — it will bundle this grammar
automatically and wire up heredoc injection out of the box. That work needs a
JDK/Gradle toolchain and is best done alongside the language server.
