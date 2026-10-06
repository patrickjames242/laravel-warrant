---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Highlighting rules in PHP
description: What makes rule text inside PHP highlight, and how to write it.
sidebar:
  order: 5
---

Rule text inside PHP is highlighted in two places: a heredoc labelled `WARRANT`,
wherever it stands, and a string passed where the package expects rule text.

## The `WARRANT` label

A heredoc or nowdoc whose label is `WARRANT` is highlighted as rule text, whatever
it is passed to:

```php
use Warrant\Facades\Warrant;

$rules = Warrant::parse(<<<'WARRANT'
    for documents {
        if is_mine or in_my_team they can view, update
        if is_locked they cannot update because 'This document is locked.'
    }
WARRANT)->ruleSet();
```

This works in VS Code, PhpStorm and Zed.

## Strings where rule text is expected

In VS Code and PhpStorm, the rule-text argument of a parsing call is highlighted
without any label. It may be a single- or double-quoted string, or a heredoc with
any label:

```php
warrant('is_owner or in_team(:team)', ['team' => $team])->conditionExpression();

Warrant::parse('for documents { if is_mine they can view }')->ruleSet();

Warrant::rule()->ifRaw('is_owner or is_admin')->theyCan('view');
```

The calls that count are `warrant()`, `Warrant::parse()`, `WarrantSyntax::parse()`,
`WarrantParser::parse()`, and a builder's `ifRaw()` and `orIfRaw()`. The argument
is recognised when it is passed first or by its parameter name. A variable or a
concatenation is plain PHP, since there is no rule text to see until it runs.

PhpStorm also highlights a string a schema method returns as rule text: from
`rules()` on a schema or a [rule provider](/supplying-rules/provider/), and from a
`#[DerivedCondition]` or `#[RuleTemplate]` method, alone or inside a returned array:

```php
#[DerivedCondition]
public function isEditable(): string
{
    return 'is_mine and not is_locked';
}
```

VS Code cannot do that one. Its grammar reads text a line at a time and cannot
tell which method a `return` belongs to, so there a returned string needs the
`WARRANT` label. Zed highlights `WARRANT` heredocs only, because the injections
into PHP belong to Zed's PHP extension.

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

## Where rule text in PHP is the right home

Rules that belong in your repository and change with deploys: a base policy, a
schema's own rules, the rules for a role that is part of the product rather
than configuration.

```php
public function rules(RuleProviderContext $context): array|RuleSetNode
{
    return Warrant::parse(<<<'WARRANT'
        if is_suspended they cannot * because 'Your account is suspended.'
        if is_super_admin they can *
    WARRANT)->ruleEntries();
}
```

When a block gets long enough to scroll, it probably wants to be a
[`.warrant` file](/editors/warrant-files/).
