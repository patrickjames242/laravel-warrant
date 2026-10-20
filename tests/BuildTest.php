<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Warrant\Builders\Build;
use Warrant\Builders\CrossSchemaCanBuilder;
use Warrant\Builders\CrossSchemaCheckBuilder;
use Warrant\Builders\Ref;
use Warrant\Builders\WarrantConditionBuilder;
use Warrant\Builders\WarrantRuleBuilder;
use Warrant\DSL\Compiling\CompilationContext;
use Warrant\DSL\Compiling\QueryFactory;
use Warrant\DSL\Compiling\RuleSetCompiler;
use Warrant\DSL\Expanding\DerivedConditionNode;
use Warrant\DSL\Parsing\ASTNodes\BooleanNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\ExpandedSyntax;
use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantRuleNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\SyntaxProducerKind;
use Warrant\DSL\Parsing\SyntaxSource;
use Warrant\DSL\Validation\ValidationInput;
use Warrant\Facades\Warrant;
use Warrant\Rules\RuleProvider;
use Warrant\Rules\RuleProviderContext;
use Warrant\Schema\Ability;
use Warrant\Schema\Conditions\RowConditionContext;
use Warrant\Schema\DerivedCondition;
use Warrant\Schema\RowCondition;
use Warrant\Schema\RuleTemplate;
use Warrant\Schema\WarrantSchema;
use Warrant\WarrantManager;

require_once __DIR__.'/Support/TestSupport.php';

/*
|------------------------------------------------------------------------------
| Build, the way in to building rules in PHP
|------------------------------------------------------------------------------
|
| A builder holds the values it was given and makes its nodes when it is
| resolved, in the source of whatever resolves it, so every tree it makes
| matches the tree the same rule text parses to.
|
*/

class BuildDoc extends Model
{
    use \Warrant\HasWarrantSchema;

    protected $table = 'build_docs';

    public static function warrantSchema(): string
    {
        return BuildDocSchema::class;
    }
}

class BuildDocSchema extends WarrantSchema
{
    public const model = BuildDoc::class;

    public static Closure $answer;

    #[Ability]
    public const VIEW = 'view';

    #[Ability]
    public const EDIT = 'edit';

    #[RowCondition]
    public function isOwner(RowConditionContext $c)
    {
        return $c->query->whereRaw('1 = 1');
    }

    #[RowCondition]
    public function ownerIs(RowConditionContext $c, string $id)
    {
        return $c->query->whereRaw('1 = 1');
    }

    #[DerivedCondition]
    public function canEditToo(): CrossSchemaCanBuilder
    {
        return Build::can('edit');
    }

    #[DerivedCondition]
    public function ownerChecked(): CrossSchemaCheckBuilder
    {
        return Build::check('is_owner')->for(BuildDocSchema::class, Ref::column('id'));
    }

    #[RuleTemplate]
    public function headless(): array
    {
        return Build::ruleSet(function ($rule) {
            $rule()->if('is_owner')->theyCan();
            $rule->include('bare_rule');
        });
    }

    #[RuleTemplate]
    public function bareRule(): WarrantRuleBuilder
    {
        return Build::rule()->if('owner_is', ['t1'])->theyCan();
    }

    public function rules(RuleProviderContext $context): mixed
    {
        return (self::$answer)();
    }
}

beforeEach(function () {
    useWarrantSchemas(['build_docs' => BuildDocSchema::class, 'course_sections' => WarrantTestSchema::class]);
});

/**
 * The rules a fresh guard expands when the schema's rules() answers with what
 * $answer returns.
 *
 * @return list<WarrantRuleNode>
 */
function buildDocRules(Closure $answer): array
{
    BuildDocSchema::$answer = $answer;
    app(WarrantManager::class)->flush();

    return Warrant::guard(makeWarrantTestUser('build'))->forSchema(BuildDocSchema::class)->expandedRuleSet()->rules;
}

/** @return list<INode> */
function builtNodes(INode $node): array
{
    $nodes = [$node];

    foreach ($node->children() as $child) {
        array_push($nodes, ...builtNodes($child));
    }

    return $nodes;
}

function parsedRule(string $text): WarrantRuleNode
{
    return WarrantSyntax::parse($text)->validateWithoutSchemas()->rule();
}

// -- the front door -------------------------------------------------------------

it('returns a rule builder from rule() and a condition builder from condition()', function () {
    expect(Build::rule())->toBeInstanceOf(WarrantRuleBuilder::class)
        ->and(Build::condition())->toBeInstanceOf(WarrantConditionBuilder::class)
        ->and(Build::condition())->not->toBeInstanceOf(WarrantRuleBuilder::class);
});

it('builds a rule set with a schema key, includes in the order written', function () {
    $built = Build::ruleSet('timesheets', function ($rule) {
        $rule()->if('is_admin')->theyCan('*');
        $rule->include('requires_approval', ['manager'])->for('view', 'edit');
        $rule->include('audit_trail')->for('*');
    });

    expect($built)->toEqualSyntax(WarrantSyntax::parse(
        "for timesheets { if is_admin they can *  @include requires_approval('manager') for view, edit  @include audit_trail for * }"
    )->validateWithoutSchemas()->ruleSet());
});

it('builds headless entries with generic clauses and includes when given only a callback', function () {
    $entries = Build::ruleSet(function ($rule) {
        $rule()->if('is_approved')->theyCan();
        $rule()->if('is_draft')->theyCannotBecause(message: 'Not approved yet.');
        $rule->include('audit_trail');
    });

    expect($entries)->toEqualSyntax(WarrantSyntax::parse(
        "if is_approved they can  if is_draft they cannot because 'Not approved yet.'  @include audit_trail"
    )->children);
});

it('places every entry of a rule set in one source', function () {
    $set = Build::ruleSet('timesheets', function ($rule) {
        $rule()->if('a')->orIf(fn ($c) => $c->if('b'))->theyCan('view');
        $rule->include('t', [1])->for('edit');
    });

    expect(array_unique(array_map(static fn (INode $node): int => spl_object_id($node->location->source), builtNodes($set))))
        ->toBe([spl_object_id($set->location->source)]);
});

// -- can(...) -------------------------------------------------------------------

it('builds can(...) to the tree its text parses to', function (string $text, Closure $build) {
    expect($build()->theyCan('x')->toRule())->toEqualSyntax(parsedRule($text));
})->with([
    'an ability on this schema' => ['if can(edit) they can x', fn () => Build::rule()->ifCan('edit')],
    'a builder with no for' => ['if can(edit) they can x', fn () => Build::rule()->ifCan(Build::can('edit'))],
    'unbound' => ['if can(view for xs_target) they can x', fn () => Build::rule()->ifCan(Build::can('view')->for('xs_target'))],
    'a key' => ['if can(view for xs_target(@context id)) they can x', fn () => Build::rule()->ifCan(Build::can('view')->for('xs_target', Ref::context('id')))],
    'a list key' => [
        'if can(assign for xs_target(@column team_id, @column starts_on)) they can x',
        fn () => Build::rule()->ifCan(Build::can('assign')->for('xs_target', [Ref::column('team_id'), Ref::column('starts_on')])),
    ],
    'as and with' => [
        'if can(view for xs_target(@context id) as t2 with tenant = @context t, plan = 3) they can x',
        fn () => Build::rule()->ifCan(Build::can('view')->for('xs_target', Ref::context('id'))->as('t2')->with(['tenant' => Ref::context('t'), 'plan' => 3])),
    ],
    'the factory closure' => [
        'if a or can(manage for xs_target(@column d_id) as d) they can x',
        fn () => Build::rule()->if('a')->orIfCan(fn ($can) => $can('manage')->for('xs_target', Ref::column('d_id'))->as('d')),
    ],
    'negated through if()' => [
        'if not can(manage for xs_target(@context id)) they can x',
        fn () => Build::rule()->ifNot(Build::can('manage')->for('xs_target', Ref::context('id'))),
    ],
]);

it('keeps an explicit null key row-bound', function () {
    $node = Build::rule()->ifCan(Build::can('view')->for('xs_target', null))->buildConditions(new SyntaxSource(null));

    expect($node)->toBeInstanceOf(CrossSchemaCanNode::class)
        ->and($node->isRowBound)->toBeTrue()
        ->and(argumentValues($node->boundKey))->toBe([null]);
});

it('throws when the ifCan factory is not called exactly once', function (Closure $factory, int $calls) {
    expect(fn () => Build::rule()->ifCan($factory))
        ->toThrow(LogicException::class, "it called it {$calls} times");
})->with([
    'never' => [fn ($can) => null, 0],
    'twice' => [fn ($can) => [$can('a'), $can('b')], 2],
]);

// -- check(...) -----------------------------------------------------------------

it('builds check(...) to the tree its text parses to', function (string $text, Closure $build) {
    expect($build()->theyCan('x')->toRule())->toEqualSyntax(parsedRule($text));
})->with([
    'one condition' => ['if check(is_open for xs_target) they can x', fn () => Build::rule()->ifCheck(Build::check('is_open')->for('xs_target'))],
    'parameters' => [
        "if check(in_region('west') for xs_target(@column p_id)) they can x",
        fn () => Build::rule()->ifCheck(Build::check('in_region', ['west'])->for('xs_target', Ref::column('p_id'))),
    ],
    'the closure form' => [
        'if is_self and check(is_open and not is_locked or (is_extended and in_grace) for xs_target(@column p_id) as pp with y = @context year) they can x',
        fn () => Build::rule()->if('is_self')->andIfCheck(fn ($check) => $check
            ->if('is_open')
            ->andIfNot('is_locked')
            ->orIf(fn ($c) => $c->if('is_extended')->andIf('in_grace'))
            ->for('xs_target', Ref::column('p_id'))
            ->as('pp')
            ->with(['y' => Ref::context('year')])),
    ],
    'negated through if()' => [
        'if a or not check(is_closed for xs_target) they can x',
        fn () => Build::rule()->if('a')->orIfNot(Build::check('is_closed')->for('xs_target')),
    ],
    'a check given to if()' => [
        'if check(is_open for xs_target) they can x',
        fn () => Build::rule()->if(Build::check('is_open')->for('xs_target')),
    ],
]);

it('rejects a check with no for() where it is written', function () {
    expect(fn () => Build::rule()->ifCheck(Build::check('is_open')))
        ->toThrow(LogicException::class, 'needs the schema it asks; call for()');
});

it('rejects a check with an empty predicate where it is written', function () {
    expect(fn () => Build::rule()->ifCheck(fn ($check) => $check->for('xs_target')))
        ->toThrow(LogicException::class, 'predicate cannot be empty');
});

it('takes no string in ifCheck', function () {
    Build::rule()->ifCheck('is_open');
})->throws(TypeError::class);

it('makes a generic denial with a message passed by name, or after an empty list', function () {
    $parsed = WarrantSyntax::parse("if is_draft they cannot because 'Not approved yet.'")->children[0];

    expect(Build::rule()->if('is_draft')->theyCannotBecause(message: 'Not approved yet.')->toRule())->toEqualSyntax($parsed)
        ->and(Build::rule()->if('is_draft')->theyCannotBecause([], 'Not approved yet.')->toRule())->toEqualSyntax($parsed);
});

it('rejects a denial with no message, rather than reading a lone ability as one', function (Closure $deny) {
    expect(fn () => $deny(Build::rule()->if('is_locked')))
        ->toThrow(LogicException::class, "theyCannotBecause() needs a message; pass it after the abilities, or by name for a clause with no abilities: theyCannotBecause(message: '...').");
})->with([
    'a lone ability' => [fn (WarrantRuleBuilder $rule) => $rule->theyCannotBecause('delete')],
    'abilities only' => [fn (WarrantRuleBuilder $rule) => $rule->theyCannotBecause(['delete', 'edit'])],
    'nothing' => [fn (WarrantRuleBuilder $rule) => $rule->theyCannotBecause()],
]);

// -- if() -------------------------------------------------------------------------

it('folds a bool into the tree as a constant', function (bool $value) {
    $conditions = Build::rule()->if($value)->theyCan('x')->toRule()->conditions;

    expect($conditions)->toBeInstanceOf(BooleanNode::class)
        ->and($conditions->value)->toBe($value);
})->with([true, false]);

it('splices a condition builder in as a group, an empty one as false', function () {
    $visible = Build::condition()->if('a')->orIf('b');

    expect(Build::rule()->if($visible)->andIfNot('c')->theyCan('x')->toRule())
        ->toEqualSyntax(parsedRule('if (a or b) and not c they can x'))
        ->and(Build::rule()->if(Build::condition())->theyCan('x')->toRule()->conditions->value)->toBeFalse();
});

it('takes an expression node as it is', function () {
    $node = WarrantSyntax::parse('a or b')->validateWithoutSchemas()->conditionExpression();

    expect(Build::rule()->if($node)->theyCan('x')->toRule()->conditions)->toBe($node);
});

it('rejects a rule given as a condition', function () {
    expect(fn () => Build::rule()->if(Build::rule()->if('a')->theyCan('x')))
        ->toThrow(LogicException::class, 'A rule is not a condition');
});

it('rejects parameters given with anything but a condition name', function (Closure $condition) {
    expect(fn () => Build::rule()->if($condition(), ['x']))
        ->toThrow(LogicException::class, 'Parameters only go with a condition name');
})->with([
    'a closure' => [fn () => fn ($c) => $c->if('a')],
    'a bool' => [fn () => true],
    'a builder' => [fn () => Build::condition()->if('a')],
]);

it('resolves a builder used twice as two trees, each in its own source', function () {
    $visible = Build::condition()->if('a')->orIf(fn ($c) => $c->if('b'));
    $check = Build::check('c')->for('xs_target');

    $one = Build::rule()->if($visible)->andIf($check)->theyCan('x')->toRule();
    $two = Build::rule()->if($visible)->andIf($check)->theyCan('x')->toRule();

    expect($one)->toEqualSyntax($two)
        ->and($one->location->source)->not->toBe($two->location->source);

    foreach ([$one, $two] as $rule) {
        expect(array_unique(array_map(static fn (INode $node): int => spl_object_id($node->location->source), builtNodes($rule))))
            ->toBe([spl_object_id($rule->location->source)]);
    }
});

// -- raw text, when and unless --------------------------------------------------------

it('builds every raw variant to the tree its text parses to', function () {
    $built = Build::rule()
        ->ifRaw('a or b')
        ->andIfRaw('c')
        ->orIfRaw('d')
        ->ifNotRaw('e or f')
        ->andIfNotRaw('g')
        ->orIfNotRaw('h')
        ->theyCan('x')
        ->toRule();

    expect($built)->toEqualSyntax(parsedRule('if (a or b) and c or d and not (e or f) and not g or not h they can x'));
});

it('applies when and unless, with a default callback', function (Closure $build, string $text) {
    expect($build()->toRule())->toEqualSyntax(parsedRule($text));
})->with([
    'when true' => [fn () => Build::rule()->when(true, fn ($r) => $r->theyCan('a'), fn ($r) => $r->theyCan('b')), 'they can a'],
    'when false' => [fn () => Build::rule()->when(false, fn ($r) => $r->theyCan('a'), fn ($r) => $r->theyCan('b')), 'they can b'],
    'unless false' => [fn () => Build::rule()->unless(false, fn ($r) => $r->theyCan('a'), fn ($r) => $r->theyCan('b')), 'they can a'],
    'unless true' => [fn () => Build::rule()->unless(true, fn ($r) => $r->theyCan('a'), fn ($r) => $r->theyCan('b')), 'they can b'],
]);

// -- through a guard ------------------------------------------------------------------

it('takes a bare rule builder from rules()', function () {
    $rules = buildDocRules(fn () => Build::rule()->if('is_owner')->theyCan('view'));

    expect($rules)->toHaveCount(1)
        ->and($rules[0]->location->source->producer()->kind)->toBe(SyntaxProducerKind::SchemaRules);
});

it('takes rules from a provider whose rules() is declared mixed', function () {
    app()->instance(RuleProvider::class, new class implements RuleProvider {
        public function rules(RuleProviderContext $context): mixed
        {
            return $context->schemaKey === 'build_docs' ? Build::rule()->if('owner_is', ['p1'])->theyCan('edit') : [];
        }
    });

    $rules = buildDocRules(fn () => Build::rule()->if('is_owner')->theyCan('view'));

    expect(array_map(static fn (WarrantRuleNode $rule): SyntaxProducerKind => $rule->location->source->producer()->kind, $rules))
        ->toBe([SyntaxProducerKind::SchemaRules, SyntaxProducerKind::RuleProvider]);
});

it('expands a template answering with headless entries and a bare rule builder', function () {
    $rules = buildDocRules(fn () => Build::ruleSet('build_docs', function ($rule) {
        $rule->include('headless')->for('edit');
    }));

    expect($rules)->toHaveCount(2)
        ->and(array_map(static fn (WarrantRuleNode $rule): array => $rule->canAbilities(), $rules))->toBe([['edit'], ['edit']])
        ->and(array_map(static fn (WarrantRuleNode $rule): SyntaxProducerKind => $rule->location->source->producer()->kind, $rules))
        ->toBe([SyntaxProducerKind::RuleTemplate, SyntaxProducerKind::RuleTemplate]);
});

it('expands a derived condition answering with a can or check builder', function (string $condition, string $node) {
    $rules = buildDocRules(fn () => Build::rule()->if($condition)->theyCan('view'));
    $derived = $rules[0]->conditions;

    expect($derived)->toBeInstanceOf(DerivedConditionNode::class)
        ->and($derived->body)->toBeInstanceOf($node)
        ->and($derived->body->location->source->producer()->kind)->toBe(SyntaxProducerKind::DerivedCondition);
})->with([
    'can' => ['can_edit_too', CrossSchemaCanNode::class],
    'check' => ['owner_checked', CrossSchemaConditionNode::class],
]);

// -- the compiler holds the validator's line -------------------------------------------

it('rejects a constant in a check(...) predicate in the compiler, unvalidated', function () {
    $rule = Build::rule()->ifCheck(Build::check()->if(true)->for('course_sections'))->theyCan('view')->toRule();

    /* Only the expander makes an expanded syntax, and it validates first; the
       compiler has to reject the constant on its own all the same. */
    $expanded = (fn () => new ExpandedSyntax('build_docs', [$rule], new ValidationInput(null)))
        ->bindTo(null, ExpandedSyntax::class)();

    (new RuleSetCompiler(new BuildDocSchema, app(WarrantManager::class)))->compile(CompilationContext::ability(
        QueryFactory::for(DB::table('build_docs')),
        makeWarrantTestUser('build'),
        'view',
        $expanded,
    ));
})->throws(InvalidArgumentException::class, 'A check(...) predicate for schema [course_sections] may not contain a constant');
