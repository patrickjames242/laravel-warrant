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

One thing goes further than the TextMate grammar can: the body of an
`@sql "..."` reference is injected as SQL, so it is highlighted as real SQL
rather than as an opaque string. Zed does not bundle SQL — it comes from a
community extension — so with none installed the body simply stays coloured as
an ordinary string. Nothing breaks either way.

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

## Giving it to a teammate without publishing

There is no Zed equivalent of a `.vsix`: no install-from-file, no way to hand
someone a bundle. Zipping this directory does not work either, because Zed's
builder always checks out the pinned grammar commit from git before it decides
whether to compile — a prebuilt `grammars/warrant.wasm` only lets it skip the
clang step, not the fetch.

So a teammate does this:

1. Clone this repository (it is public, so no access setup) and check out a
   branch where the grammar commit is **pushed** — the pin in `extension.toml`
   must resolve on GitHub.
2. Run **zed: install dev extension** and select `editors/zed`.

On their first extension build Zed downloads the wasi-sdk to get a wasm-capable
clang — about 99 MB — then shallow-checks-out the pinned commit and compiles
`parser.c`. That download is once per machine, not once per extension.

The ongoing cost is that a dev extension does not update itself. After a grammar
change they have to pull and press **Rebuild**. If more than one or two people
want this, publishing is worth it for that reason alone rather than for
discoverability: a registry install is prebuilt, so it needs no wasi-sdk, no git
fetch and no compile, and it updates on its own.

## Publishing

Submission is a pull request to Zed's registry, and it is **reviewed by
maintainers rather than merged automatically** — per their guidelines,
"submitting your extension is the first step, not a guarantee." The
prerequisites this extension has to satisfy, from
<https://zed.dev/docs/extensions/publishing/prerequisites>:

- A unique, kebab-cased id that contains neither `zed` nor `extension`.
  `warrant` qualifies, and nothing in the registry claims it (`authzed` is an
  unrelated SpiceDB extension).
- An accepted license **inside this directory** — a license at the repository
  root does not count, which is why `editors/zed/LICENSE` exists alongside the
  root one. MIT is on the accepted list.
- Functionality not already in the registry, all user-facing text in English,
  and, for a language extension, a grammar per language and no unnecessary Rust
  code. This extension ships no Rust at all.
- Tested manually in Zed **at the exact commit being submitted**, so re-check it
  after pushing rather than relying on a local `file://` install.

Then:

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
