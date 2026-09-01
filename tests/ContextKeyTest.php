<?php

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Warrant\AbilityMatchMode;
use Warrant\Facades\Warrant;
use Warrant\HasWarrantSchema;
use Warrant\Rules\WarrantRuleSet;
use Warrant\Schema\Ability;
use Warrant\Schema\Conditions\RowConditionContext;
use Warrant\Schema\RequiredContext;
use Warrant\Schema\RowCondition;
use Warrant\Schema\WarrantSchema;
require_once __DIR__.'/Support/TestSupport.php';

beforeEach(function () {
    useWarrantSchemas(['context_docs' => ContextDocSchema::class]);
});

class ContextDoc extends Model
{
    use HasWarrantSchema;

    protected $table = 'context_docs';
    public $incrementing = false;
    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return ContextDocSchema::class;
    }
}

class ContextDocSchema extends WarrantSchema
{
    public const model = ContextDoc::class;

    #[Ability] public const VIEW = 'view';
    #[Ability(requiredContext: [self::AS_OF])] public const AUDIT = 'audit';     // needs as_of_date when checked

    #[RequiredContext] public const WORKSPACE = 'workspace_id';           // required schema-wide
    public const AS_OF = 'as_of_date';                                    // usable without any declaration

    #[RowCondition]
    public function inWorkspace(RowConditionContext $c, mixed $workspace): BuilderContract
    {
        return $c->query->whereRaw('context_docs.workspace_id = ?', [$workspace]);
    }

    // Reads the ambient context bag directly — no @context argument in the rule.
    #[RowCondition]
    public function currentWorkspace(RowConditionContext $c): BuilderContract
    {
        return $c->query->whereRaw('context_docs.workspace_id = ?', [$c->context['workspace_id'] ?? null]);
    }
}

// Same schema, but with a default frame — feeds param-less paths and lets a
// check omit the required key.
class ContextDocWithDefaultsModel extends Model
{
    use HasWarrantSchema;

    protected $table = 'context_docs';

    public $incrementing = false;

    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return ContextDocWithDefaults::class;
    }
}

class ContextDocWithDefaults extends ContextDocSchema
{
    public const model = ContextDocWithDefaultsModel::class;

    protected function defaultContext(): array
    {
        return ['workspace_id' => 'w-1'];
    }
}

beforeEach(function () {
    Schema::create('context_docs', function ($table) {
        $table->string('id');
        $table->string('workspace_id');
    });

    DB::table('context_docs')->insert([
        ['id' => 'd1', 'workspace_id' => 'w-1'],
        ['id' => 'd2', 'workspace_id' => 'w-2'],
    ]);

    bindWarrantRuleSet(WarrantRuleSet::fromSyntax(
        'if in_workspace(@context workspace_id) they can view',
        'context_docs',
    ));
});

// -- reflection ---------------------------------------------------------------

it('reports the schema-wide required context keys via #[RequiredContext]', function () {
    expect(ContextDocSchema::requiredContextKeys())->toBe(['workspace_id']);
});

// -- filtering by context -----------------------------------------------------

it('filters a targeted check by the supplied context value', function () {
    $user = makeWarrantTestUser();

    expect(Warrant::guard($user)->forSchema(ContextDocSchema::class)->can('view', 'd1', ['workspace_id' => 'w-1']))->toBeTrue();
    expect(Warrant::guard($user)->forSchema(ContextDocSchema::class)->can('view', 'd1', ['workspace_id' => 'w-2']))->toBeFalse();
});

it('filters a query scope by the supplied context value', function () {
    $user = makeWarrantTestUser();

    $ids = ContextDoc::query()
        ->userHasAbility('view', $user, AbilityMatchMode::ALL, ['workspace_id' => 'w-1'])
        ->orderBy('id')
        ->pluck('id')
        ->all();

    expect($ids)->toBe(['d1']);
});

it('proxies authorize() from the model to the schema', function () {
    $user = makeWarrantTestUser();

    // Authorized → returns void, does not throw.
    Warrant::guard($user)->forSchema(ContextDocSchema::class)->authorize('view', 'd1', ['workspace_id' => 'w-1']);

    // Unauthorized → throws, exactly like the schema-level authorize().
    expect(fn () => Warrant::guard($user)->forSchema(ContextDocSchema::class)->authorize('view', 'd1', ['workspace_id' => 'w-2']))
        ->toThrow(\Warrant\WarrantAuthorizationException::class);
});

it('lets a condition read the context bag directly, without @context in the rule', function () {
    $user = makeWarrantTestUser();

    // The rule names current_workspace with no arguments; the condition reaches
    // into $c->context itself.
    bindWarrantRuleSet(WarrantRuleSet::fromSyntax('if current_workspace they can view', 'context_docs'));

    expect(Warrant::guard($user)->forSchema(ContextDocSchema::class)->can('view', 'd2', ['workspace_id' => 'w-2']))->toBeTrue();
    expect(Warrant::guard($user)->forSchema(ContextDocSchema::class)->can('view', 'd1', ['workspace_id' => 'w-2']))->toBeFalse();
});

// -- required-key enforcement -------------------------------------------------

it('throws when a required context key is missing', function () {
    $user = makeWarrantTestUser();

    expect(fn () => Warrant::guard($user)->forSchema(ContextDocSchema::class)->can('view', 'd1'))
        ->toThrow(InvalidArgumentException::class, 'requires context key(s) [workspace_id]');
});

it('lets defaultContext() satisfy a required key', function () {
    $user = makeWarrantTestUser();

    // No explicit context: the schema default (w-1) supplies workspace_id.
    useWarrantSchemas(['context_docs' => ContextDocWithDefaults::class]);

    expect(Warrant::guard($user)->forSchema(ContextDocWithDefaults::class)->can('view', 'd1'))->toBeTrue();
});

it('lets explicit context win over defaults (partial merge)', function () {
    $user = makeWarrantTestUser();

    // Explicit w-2 overrides the default w-1, so d1 (in w-1) no longer matches.
    useWarrantSchemas(['context_docs' => ContextDocWithDefaults::class]);

    expect(Warrant::guard($user)->forSchema(ContextDocWithDefaults::class)->can('view', 'd1', ['workspace_id' => 'w-2']))
        ->toBeFalse();
});

// -- per-row selection stays flat --------------------------------------------

it('computes a flat per-row ability list under a fixed context', function () {
    $user = makeWarrantTestUser();

    $rows = ContextDoc::query()
        ->selectUserAbilities($user, 'abilities', null, ['workspace_id' => 'w-1'])
        ->orderBy('id')
        ->get();

    expect(json_decode($rows[0]->abilities, true))->toBe(['view']); // d1, in w-1
    expect(json_decode($rows[1]->abilities, true))->toBe([]);       // d2, not in w-1
});

// -- undeclared keys need no declaration to be used (goal a) ------------------

it('references an undeclared @context key without a validation error', function () {
    $user = makeWarrantTestUser();

    // `region` is never declared on the schema; before, this threw at validation.
    bindWarrantRuleSet(WarrantRuleSet::fromSyntax('if in_workspace(@context region) they can view', 'context_docs'));

    // Supplying the key drives the condition (its value is used as the filter).
    expect(Warrant::guard($user)->forSchema(ContextDocSchema::class)->can('view', 'd1', ['workspace_id' => 'w-1', 'region' => 'w-1']))->toBeTrue();
    // Omitting it → the condition is simply false → denied, still no throw.
    expect(Warrant::guard($user)->forSchema(ContextDocSchema::class)->can('view', 'd1', ['workspace_id' => 'w-1']))->toBeFalse();
});

// -- per-ability required context: throw when named, skip when enumerated -----

it('throws when a named ability is missing its per-ability required context', function () {
    $user = makeWarrantTestUser();
    bindWarrantRuleSet(WarrantRuleSet::fromSyntax('they can audit if in_workspace(@context workspace_id) they can view', 'context_docs'));

    // `audit` requires as_of_date; naming it without that key throws...
    expect(fn () => Warrant::guard($user)->forSchema(ContextDocSchema::class)->can('audit', 'd1', ['workspace_id' => 'w-1']))
        ->toThrow(InvalidArgumentException::class, 'Ability [audit] requires context key(s) [as_of_date]');

    // ...while a sibling ability with no per-ability requirement is unaffected.
    expect(Warrant::guard($user)->forSchema(ContextDocSchema::class)->can('view', 'd1', ['workspace_id' => 'w-1']))->toBeTrue();

    // Supplying the key lets the named check run.
    expect(Warrant::guard($user)->forSchema(ContextDocSchema::class)->can('audit', 'd1', ['workspace_id' => 'w-1', 'as_of_date' => '2026-01-01']))->toBeTrue();
});

it('skips an ability missing its required context when enumerating no-target abilities', function () {
    $user = makeWarrantTestUser();
    bindWarrantRuleSet(WarrantRuleSet::fromSyntax('they can audit if in_workspace(@context workspace_id) they can view', 'context_docs'));

    // No as_of_date → audit is skipped (not thrown); view is targeted-only so absent no-target anyway.
    expect(Warrant::guard($user)->forSchema(ContextDocSchema::class)->abilities(null, ['workspace_id' => 'w-1']))->toBe([]);

    // Supplying it brings audit into the list.
    expect(Warrant::guard($user)->forSchema(ContextDocSchema::class)->abilities(null, ['workspace_id' => 'w-1', 'as_of_date' => '2026-01-01']))->toBe(['audit']);
});

it('skips an ability missing its required context in a per-row selection', function () {
    $user = makeWarrantTestUser();
    bindWarrantRuleSet(WarrantRuleSet::fromSyntax('they can audit if in_workspace(@context workspace_id) they can view', 'context_docs'));

    // audit (needs as_of_date, absent) is omitted; only view is computed per row.
    $rows = ContextDoc::query()
        ->selectUserAbilities($user, 'abilities', null, ['workspace_id' => 'w-1'])
        ->orderBy('id')
        ->get();

    expect(json_decode($rows[0]->abilities, true))->toBe(['view']); // d1: audit skipped
});
