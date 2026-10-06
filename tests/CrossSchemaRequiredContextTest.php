<?php

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\Facades\Warrant;
use Warrant\HasWarrantSchema;
use Warrant\Rules\RuleProvider;
use Warrant\Rules\RuleProviderContext;
use Warrant\Schema\Ability;
use Warrant\Schema\Conditions\GlobalConditionContext;
use Warrant\Schema\GlobalCondition;
use Warrant\Schema\RequiredContext;
use Warrant\Schema\WarrantSchema;
require_once __DIR__.'/Support/TestSupport.php';

/*
|------------------------------------------------------------------------------
| Required context at a reference
|------------------------------------------------------------------------------
|
| A can(...) or check(...) into schema B hands B a bag of B's defaultContext()
| with the reference's `with` map merged over it. A key B requires, or the ability
| a can(...) names requires, that is missing from that bag throws: the caller's
| context never crosses, so only the rule text can supply it.
|
| A can(...) with no `for` keeps the context it was given, so a key it is missing
| is one the caller did not pass. That reference answers unknown, which neither
| grants nor lifts a deny, and a check naming the ability directly still throws.
|
*/

beforeEach(function () {
    Schema::create('rc_docs', fn ($table) => $table->string('id'));

    useWarrantSchemas([
        'rc_docs' => RcDocSchema::class,
        'rc_regions' => RcRegionSchema::class,
        'rc_defaulted' => RcDefaultedRegionSchema::class,
    ]);
});

/**
 * @param array<string, string> $syntaxByKey
 */
function bindRequiredContextRules(array $syntaxByKey): void
{
    $sets = [];
    foreach ($syntaxByKey as $key => $syntax) {
        $sets[$key] = WarrantSyntax::parse($syntax)->scopedTo($key);
    }

    app()->instance(RuleProvider::class, new class($sets) implements RuleProvider {
        /** @param array<string, RuleSetNode> $sets */
        public function __construct(private array $sets) {}

        public function rules(RuleProviderContext $context): RuleSetNode
        {
            return $this->sets[$context->schemaKey] ?? new RuleSetNode($context->schemaKey, []);
        }
    });
}

/**
 * The normalized SQL filterQuery() emits for `view` on rc_docs, under $docSyntax.
 *
 * @param array<string, mixed> $context
 */
function rcDocFilterSql(string $docSyntax, array $context = []): string
{
    bindRequiredContextRules([
        'rc_docs' => $docSyntax,
        'rc_regions' => 'if in_region they can view  if in_region they can audit',
        'rc_defaulted' => 'if in_region they can view',
    ]);

    return normalizeWarrantSql(Warrant::guard(makeWarrantTestUser())->forSchema(new RcDocSchema)
        ->filterQuery(warrantTestQuery('rc_docs'), 'view', context: $context)
        ->toRawSql());
}

function rcSql(string $sql): string
{
    return normalizeWarrantSql($sql);
}

// -- schema-wide required keys -------------------------------------------------

it('throws for a can(...) reference missing a key its schema requires', function () {
    // The caller's `region` does not cross; only a with map or a default could.
    expect(fn () => rcDocFilterSql('if can(view for rc_regions) they can view', ['region' => 'west']))
        ->toThrow(
            InvalidArgumentException::class,
            'Schema [RcRegionSchema] requires context key(s) [region]; pass them in the `with` map of the '
                .'reference to [rc_regions], or via its defaultContext().',
        );
});

it('throws for a check(...) reference missing a key its schema requires', function () {
    expect(fn () => rcDocFilterSql('if check(in_region for rc_regions) they can view', ['region' => 'west']))
        ->toThrow(
            InvalidArgumentException::class,
            'Schema [RcRegionSchema] requires context key(s) [region]; pass them in the `with` map of the '
                .'reference to [rc_regions], or via its defaultContext().',
        );
});

it('throws for a reference missing a required key under a cannot', function () {
    expect(fn () => rcDocFilterSql('they can view  if not can(view for rc_regions) they cannot view'))
        ->toThrow(InvalidArgumentException::class, 'requires context key(s) [region]');
});

it('answers a required key passed in the with map', function () {
    expect(rcDocFilterSql('if can(view for rc_regions with region = @context area) they can view', ['area' => 'west']))
        ->toBe(rcSql('select * from "rc_docs" where (\'west\' = \'west\')'));
});

it('answers a required key the referenced schema defaults', function () {
    expect(rcDocFilterSql('if can(view for rc_defaulted) they can view'))
        ->toBe(rcSql('select * from "rc_docs" where (\'north\' = \'west\')'));
});

it('passes null for a with-map value whose @context key is absent, which supplies the key', function () {
    expect(rcDocFilterSql('if can(view for rc_regions with region = @context area) they can view'))
        ->toBe(rcSql('select * from "rc_docs" where (null = \'west\')'));
});

// -- keys the named ability requires -------------------------------------------

it('throws for a can(...) reference missing a key the named ability requires', function () {
    expect(fn () => rcDocFilterSql('if can(audit for rc_regions with region = \'west\') they can view'))
        ->toThrow(
            InvalidArgumentException::class,
            'Ability [audit] requires context key(s) [as_of]; pass them in the `with` map of the '
                .'reference to [rc_regions], or via its defaultContext().',
        );
});

it('answers a can(...) reference whose with map passes the key the named ability requires', function () {
    expect(rcDocFilterSql('if can(audit for rc_regions with region = \'west\', as_of = \'2026-01-01\') they can view'))
        ->toBe(rcSql('select * from "rc_docs" where (\'west\' = \'west\')'));
});

it('answers unknown for a can(...) of this schema missing a key the named ability requires', function () {
    expect(rcDocFilterSql('they can publish  if can(publish) they can view'))
        ->toBe(rcSql('select * from "rc_docs" where (null)'));
});

it('answers a can(...) of this schema whose context holds the key the named ability requires', function () {
    expect(rcDocFilterSql('they can publish  if can(publish) they can view', ['as_of' => '2026-01-01']))
        ->toBe(rcSql('select * from "rc_docs" where (1 = 1)'));
});

it('still throws when a check names an ability whose required key is missing', function () {
    bindRequiredContextRules(['rc_docs' => 'they can view, publish']);

    expect(fn () => Warrant::guard(makeWarrantTestUser())->forSchema(new RcDocSchema)
        ->filterQuery(warrantTestQuery('rc_docs'), 'publish'))
        ->toThrow(InvalidArgumentException::class, 'Ability [publish] requires context key(s) [as_of]');
});

// -- fixtures -----------------------------------------------------------------

class RcDoc extends Model
{
    use HasWarrantSchema;

    protected $table = 'rc_docs';
    public $incrementing = false;
    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return RcDocSchema::class;
    }
}

class RcDocSchema extends WarrantSchema
{
    public const model = RcDoc::class;

    #[Ability]
    public const VIEW = 'view';

    #[Ability(requiredContext: ['as_of'])]
    public const PUBLISH = 'publish';
}

class RcRegionSchema extends WarrantSchema
{
    #[RequiredContext]
    public const REGION = 'region';

    #[Ability]
    public const VIEW = 'view';

    #[Ability(requiredContext: ['as_of'])]
    public const AUDIT = 'audit';

    #[GlobalCondition]
    public function inRegion(GlobalConditionContext $c): BuilderContract
    {
        return $c->query->whereRaw('? = ?', [$c->context['region'], 'west']);
    }
}

class RcDefaultedRegionSchema extends WarrantSchema
{
    #[RequiredContext]
    public const REGION = 'region';

    #[Ability]
    public const VIEW = 'view';

    protected function defaultContext(): array
    {
        return ['region' => 'north'];
    }

    #[GlobalCondition]
    public function inRegion(GlobalConditionContext $c): BuilderContract
    {
        return $c->query->whereRaw('? = ?', [$c->context['region'], 'west']);
    }
}
