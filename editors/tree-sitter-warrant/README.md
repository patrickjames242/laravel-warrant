# tree-sitter-warrant

A [tree-sitter](https://tree-sitter.github.io) grammar for the Warrant
authorization rule DSL. It exists because Zed (unlike VS Code, PhpStorm and
Sublime) has no TextMate support and needs a real parser — see
`../zed/README.md`.

The PHP implementation remains the source of truth:

| This grammar | Ported from |
|--------------|-------------|
| lexical rules in `grammar.js` | `src/DSL/Lexing/Lexer.php`, `src/DSL/Lexing/TokenType.php` |
| structure in `grammar.js` | the grammar docblock atop `src/DSL/Parsing/WarrantParser.php` |

When the DSL grows a new token or form, change it there first, then mirror it
here and in `../vscode/syntaxes/warrant.tmLanguage.json`.

## Working on it

```bash
npm install
npm run generate   # grammar.js -> src/parser.c
npm test           # run test/corpus against it
```

`src/parser.c` is generated but **committed**, because Zed compiles it directly
and never runs `tree-sitter generate` itself. Commit a regenerated parser
together with the `grammar.js` change that produced it.

To see the tree for an arbitrary file:

```bash
npx tree-sitter parse ../../scratchpad.warrant
```

## Deliberately more permissive than the PHP parser

This grammar accepts a few shapes the PHP parser rejects for *semantic* reasons
— mixing a bare `for <schema>` header with braced blocks, a `because` on a `can`
clause, or mixing `:named` and `?` bindings in one source. Highlighting a
technically-invalid file sensibly beats collapsing it into one big error node,
and real diagnostics are the language server's job (`specs/language-server.md`).

The one known place it is *wrong* rather than lenient: `@contextual` lexes as
`@context` followed by an identifier, where the PHP lexer rejects it outright.
Tree-sitter's lexer has no negative lookahead to express "`@context` not
followed by an identifier character".
