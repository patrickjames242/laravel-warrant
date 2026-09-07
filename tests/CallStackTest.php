<?php

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Warrant\AbilityMatchMode;
use Warrant\Builders\WarrantConditionBuilder;
use Warrant\DSL\Compiling\Call;
use Warrant\DSL\Compiling\CallStack;
use Warrant\DSL\Compiling\CompileDepthException;
use Warrant\DSL\Compiling\CrossSchemaCycleException;
use Warrant\DSL\Parsing\ASTNodes\ColumnRef;
use Warrant\DSL\Parsing\ASTNodes\ContextRef;
use Warrant\Facades\Warrant;
use Warrant\HasWarrantSchema;
use Warrant\Rules\RuleResolutionContext;
use Warrant\Rules\RuleResolver;
use Warrant\Rules\WarrantRuleSet;
use Warrant\Schema\Ability;
use Warrant\Schema\Conditions\GlobalConditionContext;
use Warrant\Schema\Conditions\RowConditionContext;
use Warrant\Schema\GlobalCondition;
use Warrant\Schema\RowCondition;
use Warrant\Schema\WarrantSchema;

require_once __DIR__.'/Support/TestSupport.php';

/*
|------------------------------------------------------------------------------
| The compile call stack
|------------------------------------------------------------------------------
|
| The stack records every layer the compiler descends through — abilities
| resolved through can(...), cross-schema check(...) dispatches, and conditions
| that expand into further expressions — in the order they happened, because only
| that order explains how a compile reached where it did.
|
| Two guarantees ride on it and they are deliberately different: an ability is
| cycle-checked (it takes no arguments, so a repeat is the same work over again),
| while everything else is only counted against one depth budget. These tests pin
| down both, the interleaving, and the traces the exceptions render.
|
*/

beforeEach(function () {
    Schema::create('cst_docs', fn ($table) => $table->string('id'));
    Schema::create('cst_folders', function ($table) {
        $table->string('id');
        $table->string('owner');
    });
    Schema::create('cst_pings', fn ($table) => $table->string('id'));

    useWarrantSchemas([
        'cst_docs' => CstDocSchema::class,
        'cst_folders' => CstFolderSchema::class,
        'cst_pings' => CstPingSchema::class,
    ]);
});

/** @param array<string, string> $syntaxByKey */
function bindCstRules(array $syntaxByKey): void
{
    $sets = [];
    foreach ($syntaxByKey as $key => $syntax) {
        $sets[$key] = WarrantRuleSet::fromSyntax($syntax, $key);
    }

    app()->instance(RuleResolver::class, new class($sets) implements RuleResolver {
        /** @param array<string, WarrantRuleSet> $sets */
        public function __construct(private array $sets) {}

        public function resolve(RuleResolutionContext $context): WarrantRuleSet
        {
            return $this->sets[$context->schemaKey] ?? new WarrantRuleSet($context->schemaKey, []);
        }
    });
}

function compileCst(string $table, WarrantSchema $schema, string|array $abilities = 'view', array $context = []): string
{
    return Warrant::guard(makeWarrantTestUser('role-1'))
        ->forSchema($schema)
        ->filterQuery(warrantTestQuery($table), $abilities, AbilityMatchMode::ALL, $context)
        ->toRawSql();
}

// -- the stack itself ---------------------------------------------------------

it('starts empty and deepens by one call at a time', function () {
    $stack = CallStack::root();
    expect($stack->depth())->toBe(0);
    expect($stack->calls)->toBe([]);

    $deeper = $stack->enter(Call::ability(CstDocSchema::class, 'view'));

    expect($deeper->depth())->toBe(1);
    // Immutable: the parent is untouched, which is what keeps a branch's calls
    // out of its siblings.
    expect($stack->depth())->toBe(0);
});

it('does not let one branch see the calls of its sibling', function () {
    $shared = CallStack::root()->enter(Call::ability(CstDocSchema::class, 'view'));

    $left = $shared->enter(Call::ability(CstFolderSchema::class, 'view'));
    $right = $shared->enter(Call::ability(CstFolderSchema::class, 'manage'));

    expect($left->depth())->toBe(2);
    expect($right->depth())->toBe(2);
    expect($right->calls[1]->name)->toBe('manage');
});

it('rejects re-entering an ability, and says which one', function () {
    $stack = CallStack::root()
        ->enter(Call::ability(CstDocSchema::class, 'view'))
        ->enter(Call::ability(CstFolderSchema::class, 'view'));

    expect(fn () => $stack->enter(Call::ability(CstDocSchema::class, 'view')))
        ->toThrow(CrossSchemaCycleException::class, 'cst_docs:view');
});

it('does not confuse the same ability name on two schemas', function () {
    $stack = CallStack::root()
        ->enter(Call::ability(CstDocSchema::class, 'view'))
        ->enter(Call::ability(CstFolderSchema::class, 'view'));

    expect($stack->depth())->toBe(2);
});

it('lets a condition recur where an ability may not', function () {
    // Conditions are counted, never cycle-checked: the same condition twice on one
    // stack is legal, because its arguments may differ per level and the compiler
    // does not try to decide which recursions terminate.
    $stack = CallStack::root()
        ->enter(Call::condition(CstFolderSchema::class, 'is_visible'))
        ->enter(Call::condition(CstFolderSchema::class, 'is_visible'));

    expect($stack->depth())->toBe(2);
});

it('spends one budget across every kind of call', function () {
    $stack = CallStack::root()->enter(Call::ability(CstDocSchema::class, 'view'));

    // 1 ability + 63 mixed layers fills the budget exactly.
    foreach (range(1, 63) as $i) {
        $stack = $stack->enter($i % 2 === 0
            ? Call::check(CstFolderSchema::class)
            : Call::condition(CstFolderSchema::class, 'is_visible'));
    }

    expect($stack->depth())->toBe(CallStack::MAX_DEPTH);

    expect(fn () => $stack->enter(Call::check(CstFolderSchema::class)))
        ->toThrow(CompileDepthException::class, 'maximum nesting depth of 64');
});

it('renders arguments the way the author wrote them', function () {
    $call = Call::condition(CstFolderSchema::class, 'is_visible', [
        new ContextRef('tid'),
        new ColumnRef('cst_folders', 'parent_id'),
        'role-9',
        7,
        null,
    ]);

    expect($call->signature())
        ->toBe("cst_folders.is_visible(@context tid, @column cst_folders.parent_id, 'role-9', 7, null)");
});

it('falls back to the class when a schema is not registered', function () {
    useWarrantSchemas([]);

    expect(Call::ability(CstDocSchema::class, 'view')->signature())->toBe('CstDocSchema:view');
});

// -- what the compiler puts on it ---------------------------------------------

it('interleaves abilities, checks and conditions in the order they happened', function () {
    /* A's rule shows one hop. The condition behind it dispatches a check into B,
       whose predicate is a condition that references A's ability again — three
       layers the rule string cannot show, and the trace has to. */
    bindCstRules([
        'cst_docs' => 'if hops_to_folder they can view',
        'cst_folders' => 'if is_owner they can view',
    ]);

    try {
        compileCst('cst_docs', new CstDocSchema);
        $this->fail('Expected a CrossSchemaCycleException.');
    } catch (CrossSchemaCycleException $e) {
        $trace = array_map(fn (Call $c) => $c->signature(), $e->calls());

        expect($trace)->toBe([
            'cst_docs:view',
            'cst_docs.hops_to_folder',
            'check cst_folders',
            'cst_folders.back_to_doc',
            'cst_docs:view',
        ]);

        expect($e->getMessage())
            ->toContain('1. cst_docs:view')
            ->toContain('cycle starts here')
            ->toContain('5. cst_docs:view')
            ->toContain('re-enters frame 1');
    }
});

it('bounds a check that keeps dispatching itself, collapsing the repetition', function () {
    // pong expands to `check(pong for cst_pings)`, so each level costs two calls:
    // the check, then the condition it dispatches.
    bindCstRules(['cst_pings' => 'if pong they can view']);

    try {
        compileCst('cst_pings', new CstPingSchema);
        $this->fail('Expected a CompileDepthException.');
    } catch (CompileDepthException $e) {
        expect($e->calls())->toHaveCount(CallStack::MAX_DEPTH + 1);

        expect($e->getMessage())
            ->toContain('1. cst_pings:view')
            ->toContain('2. cst_pings.pong')
            ->toContain('3. check cst_pings')
            ->toContain('repeat this 2-frame segment')
            ->toContain('cannot terminate');
    }
});

it('does not charge sibling abilities for each other', function () {
    /* Both of A's abilities reference the same ability on B. They are siblings on
       one gate, not nested, so neither sees the other's calls — a stack that
       leaked between them would report a cycle here. */
    bindCstRules([
        'cst_docs' => 'if can(view for cst_folders(@context fid)) they can view '
            .'if can(view for cst_folders(@context fid)) they can edit',
        'cst_folders' => 'if is_owner they can view',
    ]);

    $sql = compileCst('cst_docs', new CstDocSchema, ['view', 'edit'], ['fid' => 'f-1']);

    expect(substr_count($sql, 'exists'))->toBe(2);
});

it('does not charge sibling conditions for each other', function () {
    /* Seventy sibling expansions, each one call deep. More than the budget in
       total, so this only compiles because depth is spent down a path and not
       across a breadth. */
    bindCstRules(['cst_folders' => 'if '.implode(' or ', array_fill(0, 70, 'is_editable')).' they can view']);

    expect(compileCst('cst_folders', new CstFolderSchema))->toContain('cst_folders.owner');
});

it('allows a recursion that shrinks a literal argument each level', function () {
    /* within(3) expands to within(2), then within(1), then a plain condition. The
       arguments differ per level, so nothing repeats and the stack unwinds well
       inside the budget — the case a cycle check keyed on the condition alone
       would have wrongly rejected. */
    bindCstRules(['cst_folders' => 'if within(3) they can view']);

    $sql = compileCst('cst_folders', new CstFolderSchema);

    expect(substr_count($sql, 'cst_folders.owner'))->toBe(4);
});

// -- fixtures -----------------------------------------------------------------

class CstDoc extends Model
{
    use HasWarrantSchema;

    protected $table = 'cst_docs';
    public $incrementing = false;
    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return CstDocSchema::class;
    }
}

class CstDocSchema extends WarrantSchema
{
    public const model = CstDoc::class;

    #[Ability]
    public const VIEW = 'view';

    #[Ability]
    public const EDIT = 'edit';

    /** Dispatches a check into another schema from inside a condition. */
    #[GlobalCondition]
    public function hopsToFolder(GlobalConditionContext $c): WarrantConditionBuilder
    {
        return (new WarrantConditionBuilder)->ifCheck('back_to_doc', CstFolderSchema::class);
    }
}

class CstFolder extends Model
{
    use HasWarrantSchema;

    protected $table = 'cst_folders';
    public $incrementing = false;
    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return CstFolderSchema::class;
    }
}

class CstFolderSchema extends WarrantSchema
{
    public const model = CstFolder::class;

    #[Ability]
    public const VIEW = 'view';

    #[Ability]
    public const MANAGE = 'manage';

    #[RowCondition]
    public function isOwner(RowConditionContext $c): BuilderContract
    {
        return $c->query->whereRaw("{$c->row('owner')} = ?", [$c->user->role_id]);
    }

    #[RowCondition]
    public function isEditable(RowConditionContext $c): WarrantConditionBuilder
    {
        return (new WarrantConditionBuilder)->if('is_owner');
    }

    /** Closes the loop back to A's ability, from inside B's predicate. */
    #[GlobalCondition]
    public function backToDoc(GlobalConditionContext $c): WarrantConditionBuilder
    {
        return (new WarrantConditionBuilder)->ifCan('view', CstDocSchema::class);
    }

    /** Recurs with a literal that decreases, so it reaches a base case. */
    #[RowCondition]
    public function within(RowConditionContext $c, int $levels): WarrantConditionBuilder
    {
        return $levels <= 0
            ? (new WarrantConditionBuilder)->if('is_owner')
            : (new WarrantConditionBuilder)->if('is_owner')->orIf('within', [$levels - 1]);
    }
}

class CstPing extends Model
{
    use HasWarrantSchema;

    protected $table = 'cst_pings';
    public $incrementing = false;
    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return CstPingSchema::class;
    }
}

class CstPingSchema extends WarrantSchema
{
    public const model = CstPing::class;

    #[Ability]
    public const VIEW = 'view';

    /** Dispatches itself into itself, forever. */
    #[GlobalCondition]
    public function pong(GlobalConditionContext $c): WarrantConditionBuilder
    {
        return (new WarrantConditionBuilder)->ifCheck('pong', CstPingSchema::class);
    }
}
