# Warrant for Zed

Syntax highlighting for the Warrant authorization rule language in
[Zed](https://zed.dev) — in `.warrant` files and in `<<<'WARRANT'` heredocs
inside PHP.

## Why this one is different

The other editors here share a single TextMate grammar
(`../vscode/syntaxes/warrant.tmLanguage.json`). Zed does not support TextMate at
all: every language it highlights is defined by a **tree-sitter grammar** — a
real parser — plus tree-sitter queries that map the resulting syntax tree to
theme colors.

So Zed support is two pieces:

| Path | What it is |
|------|------------|
| `../tree-sitter-warrant/` | The tree-sitter grammar: a parser for the DSL, generated from `grammar.js`. |
| `./` (this directory) | The Zed extension: language metadata plus the highlight/bracket/indent/outline queries. |

Zed always fetches a grammar from git rather than from an extension's own files,
which is why `extension.toml` points back at this repository and names the
subdirectory with `path`.

## What it highlights

`.warrant` files by extension, and `<<<'WARRANT'` / `<<<WARRANT` heredocs in PHP
for free — Zed's PHP extension injects whatever language the heredoc's closing
label names, so no cooperation from that extension is needed.

Highlight scopes intentionally mirror the TextMate grammar's, so a rule looks
the same across all the editors, with two exceptions where a parse tree simply
knows more than a regex can: a positional `?` is highlighted as the binding it
is rather than as a generic operator, and a `@context` key is a property rather
than falling through to the bare-identifier rule.

## Developing

The grammar's revision is pinned by commit SHA, so a change to the grammar is
always two commits: one that changes it, and one that re-pins it.

**1. Build and test the grammar.**

```bash
cd editors/tree-sitter-warrant
npm install
npm run generate
npm test
```

`src/parser.c` is generated but **committed** — Zed compiles it and never runs
`tree-sitter generate` itself, so a regenerated parser must be committed with
the `grammar.js` change that produced it.

The parser is generated at tree-sitter ABI 15. If an older Zed refuses to load
it, regenerate with `npx tree-sitter generate --abi 14` and commit that.

**2. Point the extension at your local checkout.**

Zed cannot fetch an unpushed commit, so for local work use a `file://` URL and a
local commit SHA:

```bash
git -C . rev-parse HEAD
```

Then, in `extension.toml`, set the grammar's `repository` to
`file:///absolute/path/to/laravel-warrant` and `commit` to that SHA. Revert both
before publishing.

**3. Install it in Zed.**

Open the command palette (`cmd-shift-p`) and run **zed: install dev extension**,
then choose this `editors/zed` directory.

**4. Iterate.**

- Editing a `.scm` query file: run **zed: reload extensions**.
- Editing `grammar.js`: regenerate, commit, re-pin the SHA, then press
  **Rebuild** next to the extension on the Extensions page.
- To see what the parser actually produced, run **editor: debug tree-sitter**
  and move the cursor around. That view is the fastest way to tell a grammar
  problem from a query problem.

## Publishing

1. Push the repository, with the grammar committed.
2. Set the grammar's `repository` back to the GitHub URL and `commit` to the
   pushed SHA.
3. Fork [zed-industries/extensions](https://github.com/zed-industries/extensions),
   add this repository as a submodule under `extensions/warrant`, and add the
   entry to `extensions.toml`:

   ```toml
   [warrant]
   submodule = "extensions/warrant"
   path = "editors/zed"
   version = "0.1.0"
   ```

4. Open a pull request. `version` must match `extension.toml`.
