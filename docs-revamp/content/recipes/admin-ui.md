---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: An admin permissions UI
description: Let an administrator edit rules, with validation in the loop.
sidebar:
  order: 11
---

Because rules are strings, they can be edited by someone who is not you. This is
the payoff of policy-as-data, and the part that needs care is validation.

## Storage

```php
Schema::create('role_rules', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->nullable()->constrained();
    $table->string('role');
    $table->string('schema_key');
    $table->text('rules');
    $table->foreignId('updated_by')->nullable()->constrained('users');
    $table->timestamps();
    $table->unique(['tenant_id', 'role', 'schema_key']);
});
```

Keep an audit trail. Permission changes are the thing people ask about six months
later:

```php
Schema::create('role_rule_versions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('role_rule_id')->constrained()->cascadeOnDelete();
    $table->text('rules');
    $table->foreignId('changed_by')->constrained('users');
    $table->timestamp('created_at');
});
```

## Validating on write

The important half. Two errors can come back, and both are worth surfacing
verbatim:

```php
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\WarrantSyntaxException;

class RoleRuleController extends Controller
{
    public function update(Request $request, RoleRule $roleRule)
    {
        Warrant::authorize('manage', 'settings');

        $text = $request->string('rules');

        try {
            Warrant::validate(WarrantSyntax::parse($text)->scopedTo($roleRule->schema_key));
        } catch (WarrantSyntaxException $e) {
            return back()->withErrors(['rules' => $e->getMessage()])->withInput();
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['rules' => $e->getMessage()])->withInput();
        }

        $roleRule->versions()->create([
            'rules' => $roleRule->rules,
            'changed_by' => $request->user()->id,
        ]);

        $roleRule->update(['rules' => $text, 'updated_by' => $request->user()->id]);

        Warrant::flush();

        return back()->with('status', 'Rules updated.');
    }
}
```

A syntax error comes back with a line, a column, and a caret, which is exactly what
you want to put in front of an administrator:

```text
Reserved word 'can' cannot be used as a name; expected an ability name. (line 2, column 21)

    if is_self they can can
                        ^
```

A name error comes back naming the bad name:

```text
Condition [is_mne] is not declared by the schema.
```

## Telling them what they may write

The schema knows its own vocabulary, so the form can show it:

```php
public function edit(RoleRule $roleRule)
{
    $schema = Warrant::registry()->resolveSchemaClassOrFail($roleRule->schema_key);

    return view('admin.rules.edit', [
        'roleRule'   => $roleRule,
        'abilities'  => $schema::abilityNames(),
        'conditions' => $schema::conditionKeys(),
        'templates'  => $schema::ruleTemplateKeys(),
        'required'   => $schema::requiredContextKeys(),
    ]);
}
```

Rendering those as clickable chips beside the editor turns a language people have
to learn into one they can discover.

## Previewing the effect

The strongest feature such a UI can have is showing what a change does before it
ships. Compile the proposed rules against a real user and list what they would be
able to do:

```php
public function preview(Request $request, RoleRule $roleRule)
{
    $proposed = WarrantSyntax::parse($request->string('rules'))->scopedTo($roleRule->schema_key);
    Warrant::validate($proposed);

    $subject = User::findOrFail($request->integer('user_id'));

    return app()->call(function () use ($proposed, $subject, $roleRule) {
        app()->instance(RuleResolver::class, new class($proposed) implements RuleResolver {
            public function __construct(private RuleSetNode $set) {}

            public function resolve(RuleResolutionContext $context): RuleSetNode
            {
                return $this->set;
            }
        });

        Warrant::flush();

        return [
            'possible'    => Warrant::possibleAbilities($roleRule->schema_key, $subject),
            'guaranteed'  => Warrant::guaranteedAbilities($roleRule->schema_key, $subject),
            'sampleRows'  => Document::query()
                ->userHasAbility('view', $subject)
                ->limit(10)
                ->get(['id', 'title']),
        ];
    });
}
```

Run that in a transaction you roll back, or in a separate request, since it swaps
the resolver for the process.

## Showing the effective policy

Merging means the rules an administrator edited are not the whole story. Show what
actually applies:

```php
public function effective(User $user, string $schemaKey)
{
    $set = Warrant::forSchema(
        Warrant::registry()->resolveSchemaClassOrFail($schemaKey),
        $user,
    )->resolvedRuleSet();

    return [
        'syntax' => (new WarrantSyntax([$set]))->toSyntax(),
        'rules'  => count($set->rules()),
    ];
}
```

That includes the schema's [implicit rules](/schemas/schema-policy/), which is
usually the answer to "why can they still not do it".

## Flushing

Rules are memoized per request, so a change needs a flush to take effect in the
request that made it. Across requests the memo never survives, so nothing more is
needed under PHP-FPM. Under Octane and in queue workers, Warrant drops the memo
between requests and jobs already. See
[flushing after a permission change](/production/flushing/).

## Guard the guard

The permissions screen is itself a resource:

```php
class SettingsSchema extends WarrantSchema
{
    public const model = '';

    #[Ability] public const MANAGE_PERMISSIONS = 'manage_permissions';

    #[GlobalCondition]
    public function isOwner(GlobalConditionContext $c): bool
    {
        return $c->user->is_account_owner;
    }
}
```

```php
Warrant::authorize('manage_permissions', 'settings');
```

Do that with an [implicit rule](/schemas/schema-policy/) rather than a stored one,
so nobody can edit their way into editing permissions.
