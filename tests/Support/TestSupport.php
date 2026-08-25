<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Warrant\Ability;
use Warrant\Facades\Warrant;
use Warrant\GlobalCondition;
use Warrant\HasWarrantSchema;
use Warrant\RuleResolutionContext;
use Warrant\RuleResolver;
use Warrant\RuleSyntaxTree\WarrantRule;
use Warrant\RuleSyntaxTree\WarrantRuleSet;
use Warrant\Schema\Conditions\GlobalConditionContext;
use Warrant\Schema\Conditions\RowConditionContext;
use Warrant\RowCondition;
use Warrant\Schema\WarrantSchema;
use Warrant\WarrantManager;

class WarrantTestUser implements Authenticatable
{
    public function __construct(public ?string $role_id = null) {}

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->role_id;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): ?string
    {
        return null;
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): ?string
    {
        return null;
    }
}

class WarrantTestModel extends Model
{
    protected $table = 'course_sections';

    public $incrementing = false;

    protected $keyType = 'string';
}

class WarrantTestSchema extends WarrantSchema
{
    public const model = WarrantTestModel::class;

    #[Ability]
    public const ABILITY_CREATE = 'create';

    #[Ability]
    public const ABILITY_PUBLISH = 'publish';

    #[Ability]
    public const ABILITY_ARCHIVE = 'archive';

    #[Ability]
    public const ABILITY_VIEW = 'view';

    #[Ability]
    public const ABILITY_UPDATE = 'update';

    #[RowCondition]
    public function isTeacher(RowConditionContext $c): BuilderContract
    {
        return $c->query->whereRaw("{$c->row()} = ?", ["teacher:{$c->user->role_id}"]);
    }

    #[GlobalCondition]
    public function isAdvisor(GlobalConditionContext $c): BuilderContract
    {
        return $c->query->whereRaw('? = ?', ['advisor', $c->user->role_id]);
    }

    /**
     * A relational condition expressed as a correlated whereExists subquery — the
     * required idiom for reaching another table (conditions may only add where
     * clauses, so a top-level join is rejected; see WarrantJoinConditionSchema).
     */
    #[RowCondition]
    public function viaJoin(RowConditionContext $c): BuilderContract
    {
        return $c->query->whereExists(fn (BuilderContract $sub) => $sub
            ->from('enrollments')
            ->whereColumn('enrollments.section_id', $c->row())
            ->whereRaw('enrollments.user_id = ?', [$c->user->role_id]));
    }
}

class WarrantJoinConditionSchema extends WarrantSchema
{
    public const model = WarrantTestModel::class;

    #[Ability]
    public const ABILITY_VIEW = 'view';

    // Illegal: a condition may only add where clauses. Emitting a top-level join
    // is rejected by the compiler with a whereExists() pointer.
    #[RowCondition]
    public function viaBadJoin(RowConditionContext $c): BuilderContract
    {
        return $c->query
            ->join('enrollments', 'enrollments.section_id', '=', $c->row())
            ->whereRaw('enrollments.user_id = ?', [$c->user->role_id]);
    }
}

class WarrantBooleanConditionSchema extends WarrantSchema
{
    public const model = WarrantTestModel::class;

    #[Ability]
    public const ABILITY_VIEW = 'view';

    #[GlobalCondition]
    public function isSuperUser(GlobalConditionContext $c): bool
    {
        return $c->user->role_id === 'super-role';
    }
}

class MistypedConditionSchema extends WarrantSchema
{
    public const model = WarrantTestModel::class;

    #[Ability]
    public const ABILITY_VIEW = 'view';

    // Marked targeted but typed with the global context — a boot-time mistake.
    #[RowCondition]
    public function isWrong(GlobalConditionContext $c): BuilderContract
    {
        return $c->query;
    }
}

class ExtraParamConditionSchema extends WarrantSchema
{
    public const model = WarrantTestModel::class;

    #[Ability]
    public const ABILITY_VIEW = 'view';

    // One context parameter is the whole contract; a second is a mistake.
    #[GlobalCondition]
    public function isWrong(GlobalConditionContext $c, string $extra): bool
    {
        return $extra !== '';
    }
}

class WarrantScopedModel extends Model
{
    use HasWarrantSchema;

    protected $table = 'course_sections';

    public $incrementing = false;

    protected $keyType = 'string';

    public function warrantSchema(): string
    {
        return WarrantScopedModelSchema::class;
    }
}

class WarrantMismatchedScopedModel extends Model
{
    use HasWarrantSchema;

    protected $table = 'course_sections';

    public $incrementing = false;

    protected $keyType = 'string';

    public function warrantSchema(): string
    {
        return WarrantTestSchema::class;
    }
}

class WarrantScopedModelSchema extends WarrantTestSchema
{
    public const model = WarrantScopedModel::class;
}

class WarrantImplicitRulesSchema extends WarrantTestSchema
{
    protected function implicitRules(): array
    {
        return [
            // Always grant publish, and never allow archive, regardless of the resolver.
            WarrantRule::fromSyntax('they can publish'),
            WarrantRule::fromSyntax('they cannot archive'),
        ];
    }
}

class FakeWarrantRuleResolver implements RuleResolver
{
    public function __construct(private WarrantRuleSet $ruleSet) {}

    public function resolve(RuleResolutionContext $context): WarrantRuleSet
    {
        return $this->ruleSet;
    }
}

/**
 * @param  array<int, class-string<WarrantSchema>>  $schemas
 */
function useWarrantSchemas(array $schemas): void
{
    config()->set('warrant.schemas', $schemas);
    app()->forgetInstance(WarrantManager::class);
    Warrant::clearResolvedInstances();
}

/**
 * Bind the resolver to a rule set built from Warrant syntax. The schema key is
 * irrelevant to the fake (the schema asks for its own rules), so it defaults to
 * the course_sections fixture schema key.
 */
function bindWarrantRules(string $syntax, array $bindings = [], string $schemaKey = 'course_sections'): void
{
    app()->instance(
        RuleResolver::class,
        new FakeWarrantRuleResolver(WarrantRuleSet::fromSyntax($schemaKey, $syntax, $bindings))
    );
}

/**
 * Bind the resolver to an explicit rule set.
 */
function bindWarrantRuleSet(WarrantRuleSet $ruleSet): void
{
    app()->instance(RuleResolver::class, new FakeWarrantRuleResolver($ruleSet));
}

function makeWarrantTestUser(?string $roleId = 'role-1'): Authenticatable
{
    return new WarrantTestUser($roleId);
}

function warrantTestQuery(string $table = 'course_sections'): BuilderContract
{
    return DB::connection()->table($table);
}

/**
 * Normalize an SQL string to a canonical form so two queries that differ only in
 * formatting compare equal, while staying valid SQLite.
 *
 * It tokenizes the SQL (so string literals and quoted identifiers are preserved
 * verbatim — their inner spaces, commas and parentheses are never touched), then:
 *
 * - collapses all insignificant whitespace and newlines (re-joined with a single
 *   canonical spacing rule, so `a , b`, `a ,b` and `a,b` all converge);
 * - strips SQL comments (`-- ...`, block comments) and any trailing semicolons;
 * - lower-cases unquoted words (keywords and unquoted identifiers — case-insensitive
 *   in SQLite), leaving quoted identifiers and string literals as-is;
 * - removes *redundant doubled* parentheses: `((E))` → `(E)` (always safe, since
 *   `(( E ))` is exactly `( E )`).
 *
 * It deliberately does NOT attempt precedence-aware paren removal, operand/clause
 * reordering, or identifier-quoting unification — those need a real SQL parser and
 * can change meaning. Two strings that are only *semantically* (not syntactically)
 * equal are not guaranteed to converge.
 */
function normalizeWarrantSql(string $sql): string
{
    // Ordered token patterns. Whitespace and comments are dropped; quoted forms
    // (string, "id", [id], `id`) are captured whole so their contents survive intact.
    $patterns = [
        'ws' => '\s+',
        'comment' => '--[^\n]*|/\*.*?\*/',
        'str' => "'(?:[^']|'')*'",
        'qid' => '"(?:[^"]|"")*"',
        'bid' => '\[[^\]]*\]',
        'tid' => '`(?:[^`]|``)*`',
        'num' => '0[xX][0-9a-fA-F]+|\d+(?:\.\d+)?(?:[eE][+-]?\d+)?',
        'param' => '[:@$][A-Za-z_][A-Za-z0-9_]*|\?\d*',
        'word' => '[A-Za-z_][A-Za-z0-9_]*',
        'op' => '<=|>=|<>|!=|\|\||<<|>>',
        'char' => '.',
    ];

    $named = [];
    foreach ($patterns as $name => $pattern) {
        $named[] = "(?<$name>$pattern)";
    }
    $regex = '~\G(?:'.implode('|', $named).')~s';

    $values = [];
    $pos = 0;
    $len = strlen($sql);

    while ($pos < $len && preg_match($regex, $sql, $m, PREG_UNMATCHED_AS_NULL, $pos)) {
        $pos += strlen($m[0]);

        if (($m['ws'] ?? null) !== null || ($m['comment'] ?? null) !== null) {
            continue;
        }

        // Fold only unquoted words; everything quoted is kept verbatim.
        $values[] = ($m['word'] ?? null) !== null ? strtolower($m[0]) : $m[0];
    }

    // Drop trailing statement terminators.
    while ($values !== [] && end($values) === ';') {
        array_pop($values);
    }

    $values = array_values($values);

    // Match parentheses, then remove any outer pair that wraps a single group with
    // nothing else between the two opens or the two closes: ((E)) -> (E).
    $stack = [];
    $match = [];
    foreach ($values as $i => $value) {
        if ($value === '(') {
            $stack[] = $i;
        } elseif ($value === ')' && $stack !== []) {
            $open = array_pop($stack);
            $match[$open] = $i;
        }
    }

    $remove = [];
    foreach ($match as $open => $close) {
        if (($values[$open + 1] ?? null) === '(' && ($match[$open + 1] ?? -1) === $close - 1) {
            $remove[$open] = true;
            $remove[$close] = true;
        }
    }

    if ($remove !== []) {
        $values = array_values(array_filter(
            $values,
            fn (int $i): bool => ! isset($remove[$i]),
            ARRAY_FILTER_USE_KEY,
        ));
    }

    // Re-join with canonical spacing (single space, except tight around these).
    $noSpaceBefore = [')', ',', '.', ';'];
    $noSpaceAfter = ['(', '.'];

    $out = '';
    $prev = null;
    foreach ($values as $value) {
        if ($prev !== null && ! in_array($value, $noSpaceBefore, true) && ! in_array($prev, $noSpaceAfter, true)) {
            $out .= ' ';
        }
        $out .= $value;
        $prev = $value;
    }

    return $out;
}
