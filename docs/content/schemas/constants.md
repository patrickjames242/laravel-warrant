---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Constants
description: Name a fixed value once in a schema, pass it to conditions by name, and group abilities.
sidebar:
  order: 3
---

A schema can give a fixed value a name, so rules write the name instead of the
value. Declare it as a class constant marked `#[Constant]`:

```php
use Warrant\Schema\Abilities\Ability;
use Warrant\Schema\Constants\Constant;

final class PayrollSchema extends ModelSchema
{
    #[Ability] public const VIEW    = 'view';
    #[Ability] public const EDIT    = 'edit';
    #[Ability] public const APPROVE = 'approve';

    #[Constant]
    public const PAYROLL_MODULE = 'payroll';

    #[Constant]
    public const EDITORS = [self::VIEW, self::EDIT];
}
```

A rule then names the constant wherever it would write the value:

```warrant
if module_enabled(payroll_module) they can editors
```

`module_enabled` receives `'payroll'`, and the rule grants `view` and `edit`, as
if both had been written out.

## Names

A constant's name in rule text is its PHP name in lowercase snake_case. A name in
capitals is lowercased, and any other is snake-cased:

```php
#[Constant] public const PAYROLL_MODULE = 'payroll';   // payroll_module
#[Constant] public const payrollModule  = 'payroll';   // payroll_module
```

Give the attribute a name to choose it yourself. It is used exactly as written:

```php
#[Constant('module')] public const PAYROLL_MODULE = 'payroll';   // module
```

This differs from an ability on purpose. An [ability](/schemas/abilities/) is
named by its constant's **value**, a constant by its constant's **name**.

## Where a constant goes

A constant stands wherever an argument does: a condition's arguments, a handle's
row selector, a `with` map value and an `@include`'s arguments. It may also be the
body of an `@sql` reference, or a denial message:

```warrant
if in_period(current_period) and check(in_scope(region) for folders(@context folder_id))
they cannot export because export_locked_message

if matches(@sql open_periods_query) they can view
```

An `@sql` body and a denial message need a string, so a constant there must hold
one:

```text
An @sql constant must be a string, but [current_period] is int.
A denial message must be a string, but constant [editors] is array.
```

## How a name is found

A bare name is looked up the way a [condition is](/schemas/global-schemas/#how-a-name-is-found):
first in the schema the rule text was written in, then in each
[global schema](/schemas/global-schemas/) in the order they are listed. The first
schema that declares the name answers. Text a global schema answered with looks in
the global schemas alone.

Inside a `check(...)` predicate a name belongs to the schema the check names, as
the predicate's conditions do. The handle's own arguments are written in the
enclosing rule, so a constant there is looked up from the rule's schema:

```warrant
# `region` is a constant of folders; `current_folder` is one of this schema.
if check(in_scope(region) for folders(current_folder)) they can view
```

To reach a constant of any other schema, write that schema's key before it:

```warrant
if module_enabled(billing.payroll_module) they can view
```

A prefix looks in the named schema alone. A global schema has no key, so a
prefixed name never reaches one, even when the named schema does not declare the
constant:

```text
Constant [billing.payroll_module]: schema [billing] declares no constant [payroll_module]. A schema prefix reaches that schema's own constants alone, never a global schema's.
```

A name nothing declares is reported with the closest names that are:

```text
Constant [payroll_modle] is declared by neither schema [payroll] nor any global schema. Did you mean [payroll_module]?
```

## Values

A constant holds whatever PHP lets a class constant hold: a string, a number, a
bool, null, an array, an enum case. Warrant puts no limit on the value. Where the
constant is used decides what the value must be.

A condition receives the value exactly as PHP holds it, as it would from a
[binding](/rules/values/#named-bindings). An array stays an array, and an enum
case stays the enum instance, never its `->value`:

```php
enum Status: string
{
    case Open = 'open';
}

#[Constant] public const OPEN = Status::Open;

#[RowCondition]
public function hasStatus(RowConditionContext $c, Status $status): Builder
{
    // has_status(open)  ->  $status === Status::Open
    return $c->query->where($c->column('status'), $status);
}
```

Laravel turns a backed enum into its value when it binds the query, so the
condition can pass the case straight to `where()`.

## Groups of abilities

A constant also stands wherever an ability does: in a `they can` or `they cannot`
list, an ability block header, an `@include`'s `for` list and a `can(...)`. It
works exactly as a [binding standing for abilities](/rules/values/#abilities-from-a-binding)
does. A list is spliced into the list where the constant stands, so a constant
holding a list of abilities is a group you grant, deny and check as one:

```php
#[Constant] public const EDITORS  = [self::VIEW, self::EDIT];
#[Constant] public const REVIEWER = self::APPROVE;
#[Constant] public const ALL      = ['*'];
```

```warrant
if is_editor they can editors, archive
can they editors { if is_owner they can }
@include owner_only for editors
if is_admin they can all
if can(reviewer) they can view
```

A bare name where an ability goes is the ability when the schema declares one of
that name, and otherwise the constant of that name, looked up as above. A string
constant names one ability, and `can(...)` asks about one, so a list there is a
mistake.

The value must name at least one ability, and every ability it names must be one
the schema declares:

```text
The constant [teams] stands in an ability list, so its value is an ability name or a non-empty list of them; got a list holding int.
The constant [editors] stands for the one ability a can(...) reference asks about, so its value is an ability name; got array.
Ability [publish] is not declared by the schema.
```

The abilities of a `can(... for <schema>)` belong to the schema it names, so a
constant there is looked up from that schema, then the globals:

```warrant
# folders' own `sharer`, not this schema's.
if can(sharer for folders(@context folder_id)) they can view
```

[Each ability once](/rules/basics/#each-ability-once) is judged on the list as
written, never on what a constant holds: `they can view, editors` is fine, while
`they can editors, editors` names one constant twice.

## Abilities are constants

Every ability is a constant too, named by its value and holding it. So a rule can
pass another schema's ability to a condition by name:

```warrant
if has_ability_for_every_user_in_group(users.view_payroll) they can approve
```

The condition receives the string `'view_payroll'`.

## Names a schema may not use

These are refused when the schema is read:

- Two constants of one schema with the same name, `PAYROLL_MODULE` and
  `payrollModule` among them.
- A constant named as one of the schema's abilities.
- A constant marked both `#[Ability]` and `#[Constant]`.
- A constant or ability named as a keyword or a literal, such as `if`, `can`,
  `true` or `null`, which rule text could never name.

No two [global schemas](/schemas/global-schemas/#no-two-globals-share-a-name)
may declare the same constant, as for conditions and templates.

A constant may share its name with a condition or a template. A constant is read
only where an argument or an ability goes, and a condition only where a condition
goes, so the two never meet.

## In the editor

The [language server](/editors/language-server/) completes constants, shows a
constant's value on hover, jumps to its PHP declaration and finds every use of
it. A bare constant looks like a condition or an ability to a grammar, so the
language server colours it as a constant itself.
