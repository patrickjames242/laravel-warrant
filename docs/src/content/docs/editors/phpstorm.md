---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: PhpStorm
description: Importing the TextMate bundle, and injecting the language into heredocs.
sidebar:
  order: 3
---

PhpStorm and the other IntelliJ IDEs read TextMate grammars, so the same grammar
VS Code uses works here. It is two pieces of setup rather than an extension
install.

See `editors/phpstorm/README.md` in the repository for the current details.

## 1. Import the grammar

Settings, then Editor, then TextMate Bundles. Add the `editors/vscode` directory
from the repository: the bundle format is close enough that PhpStorm reads the
extension folder directly.

That gets `.warrant` files highlighted immediately.

```warrant
for documents {
    if is_mine they can view, update
    if is_locked they cannot update because 'This document is locked.'
}
```

## 2. Inject into heredocs

PhpStorm does not inject by heredoc label on its own, so `WARRANT` heredocs need a
Language Injection rule.

Settings, then Editor, then Language Injections, then add a PHP injection matching
heredocs whose label is `WARRANT`, with Warrant as the injected language.

The quick version, per occurrence, is the `@lang` annotation comment, which also
documents the intent for anyone reading the file:

```php
/** @lang Warrant */
$rules = <<<'WARRANT'
    for documents {
        if is_mine they can view
    }
WARRANT;
```

## What you get

The same colouring as everywhere else: keywords, operators, symbolic references,
placeholders, string literals, comments, and names.

## What you do not

No diagnostics, no completion, no go-to-definition on a condition name. A TextMate
grammar is a lexer and knows nothing about your schemas.

A native plugin backed by a language server is [planned](/roadmap/planned/), and
PhpStorm is one of the two editors it is designed for.

## Until then

Two habits close most of the gap.

Keep the schema in the string's own header, so anything reading your source knows
which vocabulary applies:

```php
Warrant::ruleSet('for documents { if is_mine they can view }');
```

And run [`validate()`](/supplying-rules/validation/) in a test over stored rules, so
a misspelled condition fails CI rather than a request.
