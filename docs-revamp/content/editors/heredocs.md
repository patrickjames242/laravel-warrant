---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Highlighting rules in PHP
description: The WARRANT heredoc label, and how to write rule text inside PHP.
sidebar:
  order: 5
---

Rule text inside PHP is highlighted when the heredoc's label is `WARRANT`. That
label is the whole mechanism: the injection grammar matches on it.

```php
use Warrant\Facades\Warrant;

$rules = Warrant::parse(<<<'WARRANT'
    for documents {
        if is_mine or in_my_team they can view, update
        if is_locked they cannot update because 'This document is locked.'
    }
WARRANT)->ruleSet();
```

Automatic in VS Code and Zed. PhpStorm needs a
[Language Injection rule](/editors/phpstorm/) or an `@lang` annotation.

## Prefer the nowdoc

Quote the opening label and PHP does no interpolation:

```php
<<<'WARRANT'   // nowdoc: no interpolation
<<<WARRANT     // heredoc: $variables are interpolated
```

Use the quoted form. Rule text should not be interpolating PHP values, for two
reasons.

**It is unsafe.** A quote inside an interpolated value ends the rule's string
literal early, and what follows is parsed as rule syntax:

```php
$team = "sales' they can *; if x";

// Now the rule text says something you did not write.
Warrant::parse(<<<WARRANT
    for documents { if in_team('{$team}') they can view }
WARRANT)->ruleSet();
```

**Bindings exist for this**, and they accept any PHP value rather than only strings:

```php
Warrant::parse(<<<'WARRANT'
    for documents { if in_team(:team) they can view }
WARRANT, bindings: ['team' => $team])->ruleSet();
```

See [passing values to conditions](/rules/values/).

## Indentation

PHP 7.3 and later strip the closing label's indentation from every line, so a
heredoc indents with the surrounding code:

```php
class DocumentRules
{
    public function forEditor(): RuleSetNode
    {
        return Warrant::parse(<<<'WARRANT'
            for documents {
                if is_mine they can view, update
            }
            WARRANT)->ruleSet();
    }
}
```

Whitespace is insignificant in the language anyway, so this is purely cosmetic.

## Put the schema in the header

```php
// Better: the header travels with the string.
Warrant::parse(<<<'WARRANT'
    for documents { if is_mine they can view }
WARRANT)->ruleSet();

// Works, but the string is unverifiable from the outside.
Warrant::parse('if is_mine they can view')->scopedTo('documents');
```

Anything reading your source, including a future language server, can check the
first against a schema and cannot check the second. A header that disagrees with
`scopedTo()` is an error, so there is no ambiguity in having both.

## Where a heredoc is the right home

Rules that belong in your repository and change with deploys: a base policy, an
implicit rule on a schema, the rules for a role that is part of the product rather
than configuration.

```php
public function implicitRules(): array|RuleSetNode
{
    return Warrant::parse(<<<'WARRANT'
        if is_suspended they cannot * because 'Your account is suspended.'
        if is_super_admin they can *
    WARRANT)->ruleEntries();
}
```

When a block gets long enough to scroll, it probably wants to be a
[`.warrant` file](/editors/warrant-files/).
