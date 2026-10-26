<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Warrant\DSL\Parsing\ASTNodes\AndNode;
use Warrant\DSL\Parsing\ASTNodes\BindingNode;
use Warrant\DSL\Parsing\ASTNodes\BooleanNode;
use Warrant\DSL\Parsing\ASTNodes\ColumnRef;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\ContextRef;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\ExpandedSyntax;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\ASTNodes\InvalidSyntax;
use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\ASTNodes\ISchemaScopedNode;
use Warrant\DSL\Parsing\ASTNodes\LiteralNode;
use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\ASTNodes\OrNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\SqlRef;
use Warrant\DSL\Parsing\ASTNodes\ValidSyntax;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\ASTNodes\WithEntryNode;
use Warrant\DSL\Parsing\BoundSyntax;
use Warrant\DSL\Parsing\Diagnostic;
use Warrant\DSL\Parsing\Positions\SyntaxLocation;
use Warrant\DSL\Parsing\SyntaxProducer;
use Warrant\DSL\Parsing\WarrantParser;
use Warrant\DSL\Validation\GenericMode;
use Warrant\DSL\Validation\ValidationInput;
use Warrant\DSL\ISchemaCatalog;
use Warrant\DSL\ISchemaVocabulary;
use Warrant\Facades\Warrant;
use Warrant\HasWarrantSchema;
use Warrant\Registry\RegisteredSchemas;
use Warrant\Rules\IRuleProvider;
use Warrant\Rules\RuleProviderContext;
use Warrant\Schema\Abilities\Ability;
use Warrant\Schema\Conditions\Global\GlobalConditionContext;
use Warrant\Schema\Conditions\Row\RowConditionContext;
use Warrant\Schema\Conditions\Global\GlobalCondition;
use Warrant\Schema\Conditions\Row\RowCondition;
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
    use HasWarrantSchema;

    protected $table = 'course_sections';

    public $incrementing = false;

    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return WarrantTestSchema::class;
    }
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
        return $c->query->whereRaw("{$c->column()} = ?", ["teacher:{$c->user->role_id}"]);
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
            ->whereColumn('enrollments.section_id', $c->column())
            ->whereRaw('enrollments.user_id = ?', [$c->user->role_id]));
    }
}

class WarrantJoinConditionModel extends Model
{
    use HasWarrantSchema;

    protected $table = 'course_sections';

    public $incrementing = false;

    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return WarrantJoinConditionSchema::class;
    }
}

class WarrantJoinConditionSchema extends WarrantSchema
{
    public const model = WarrantJoinConditionModel::class;

    #[Ability]
    public const ABILITY_VIEW = 'view';

    // Illegal: a condition may only add where clauses. Emitting a top-level join
    // is rejected by the compiler with a whereExists() pointer.
    #[RowCondition]
    public function viaBadJoin(RowConditionContext $c): BuilderContract
    {
        return $c->query
            ->join('enrollments', 'enrollments.section_id', '=', $c->column())
            ->whereRaw('enrollments.user_id = ?', [$c->user->role_id]);
    }
}

class WarrantBooleanConditionModel extends Model
{
    use HasWarrantSchema;

    protected $table = 'course_sections';

    public $incrementing = false;

    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return WarrantBooleanConditionSchema::class;
    }
}

class WarrantBooleanConditionSchema extends WarrantSchema
{
    public const model = WarrantBooleanConditionModel::class;

    #[Ability]
    public const ABILITY_VIEW = 'view';

    #[GlobalCondition]
    public function isSuperUser(GlobalConditionContext $c): bool
    {
        return $c->user->role_id === 'super-role';
    }
}

class MistypedConditionModel extends Model
{
    use HasWarrantSchema;

    protected $table = 'course_sections';

    public $incrementing = false;

    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return MistypedConditionSchema::class;
    }
}

class MistypedConditionSchema extends WarrantSchema
{
    public const model = MistypedConditionModel::class;

    #[Ability]
    public const ABILITY_VIEW = 'view';

    // Marked targeted but typed with the global context — a boot-time mistake.
    #[RowCondition]
    public function isWrong(GlobalConditionContext $c): BuilderContract
    {
        return $c->query;
    }
}

class ParameterizedConditionModel extends Model
{
    use HasWarrantSchema;

    protected $table = 'course_sections';

    public $incrementing = false;

    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return ParameterizedConditionSchema::class;
    }
}

class ParameterizedConditionSchema extends WarrantSchema
{
    public const model = ParameterizedConditionModel::class;

    #[Ability]
    public const ABILITY_VIEW = 'view';

    // A required DSL argument bound as a parameter: arg[0] -> $userId.
    #[RowCondition]
    public function isSpecificUser(RowConditionContext $c, string $userId): BuilderContract
    {
        return $c->query->whereRaw("{$c->column()} = ?", [$userId]);
    }

    // An optional parameter falls back to its default when no argument is supplied.
    #[GlobalCondition]
    public function roleIs(GlobalConditionContext $c, string $role = 'guest'): bool
    {
        return $c->user->role_id === $role;
    }

    // The first parameter binds arg[0]; arguments beyond the declared parameters
    // are not passed positionally but remain reachable via $c->arguments.
    #[GlobalCondition]
    public function hasExtraArgs(GlobalConditionContext $c, string $first): bool
    {
        return $first === ($c->arguments[0] ?? null) && ($c->arguments[1] ?? null) === 'b';
    }
}

/**
 * A model subclass that inherits warrantSchema() from its parent, so it names a
 * schema that names the *parent* back. Consistent read from the schema end, wrong
 * from the model end — the case the model direction of the cross-check catches.
 */
class WarrantSubclassedModel extends WarrantTestModel
{
}

class WarrantScopedModel extends Model
{
    use HasWarrantSchema;

    protected $table = 'course_sections';

    public $incrementing = false;

    protected $keyType = 'string';

    public static function warrantSchema(): string
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

    public static function warrantSchema(): string
    {
        return WarrantTestSchema::class;
    }
}

class WarrantScopedModelSchema extends WarrantTestSchema
{
    public const model = WarrantScopedModel::class;
}

class WarrantSchemaRulesModel extends Model
{
    use HasWarrantSchema;

    protected $table = 'course_sections';

    public $incrementing = false;

    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return WarrantSchemaRulesSchema::class;
    }
}

class WarrantSchemaRulesSchema extends WarrantTestSchema
{
    public const model = WarrantSchemaRulesModel::class;

    public function rules(RuleProviderContext $context): array
    {
        return [
            // Always grant publish, and never allow archive, regardless of the provider.
            WarrantSyntax::parse('they can publish')->validateWithoutSchemas()->rule(),
            WarrantSyntax::parse('they cannot archive')->validateWithoutSchemas()->rule(),
        ];
    }
}

class WarrantSchemaRuleSetModel extends Model
{
    use HasWarrantSchema;

    protected $table = 'course_sections';

    public $incrementing = false;

    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return WarrantSchemaRuleSetSchema::class;
    }
}

class WarrantSchemaRuleSetSchema extends WarrantTestSchema
{
    public const model = WarrantSchemaRuleSetModel::class;

    public function rules(RuleProviderContext $context): array|RuleSetNode
    {
        // Same rules as WarrantSchemaRulesSchema, but returned as a rule set.
        return WarrantSyntax::parse(
            "they can publish\nthey cannot archive",
        )->validateWithoutSchemas()->scopedTo(self::schemaKey());
    }
}

class WarrantUserRulesModel extends Model
{
    use HasWarrantSchema;

    protected $table = 'course_sections';

    public $incrementing = false;

    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return WarrantUserRulesSchema::class;
    }
}

/** Rules that depend on who is asking: only the `editor-role` user may publish. */
class WarrantUserRulesSchema extends WarrantTestSchema
{
    public const model = WarrantUserRulesModel::class;

    public function rules(RuleProviderContext $context): string
    {
        return $context->user?->role_id === 'editor-role' ? 'they can publish' : '';
    }
}

class FakeWarrantRuleProvider implements IRuleProvider
{
    public function __construct(private RuleSetNode $ruleSet) {}

    public function rules(RuleProviderContext $context): RuleSetNode
    {
        return $this->ruleSet;
    }
}

/**
 * @param  array<string, class-string<WarrantSchema>>  $schemas  Schema classes
 *   keyed by schema key, exactly as `warrant.schemas` is configured.
 */
function useWarrantSchemas(array $schemas): void
{
    config()->set('warrant.schemas', $schemas);
    app()->forgetInstance(WarrantManager::class);
    Warrant::clearResolvedInstances();
}

/**
 * Bind the provider to a rule set built from Warrant syntax. The schema key is
 * irrelevant to the fake (the schema asks for its own rules), so it defaults to
 * the course_sections fixture schema key.
 */
function bindWarrantRules(string $syntax, array $bindings = [], string $schemaKey = 'course_sections'): void
{
    app()->instance(
        IRuleProvider::class,
        new FakeWarrantRuleProvider(WarrantSyntax::parse(new BoundSyntax($syntax, $bindings))->validateWithoutSchemas()->scopedTo($schemaKey))
    );
}

/**
 * Bind the provider to an explicit rule set.
 */
function bindWarrantRuleSet(RuleSetNode $ruleSet): void
{
    app()->instance(IRuleProvider::class, new FakeWarrantRuleProvider($ruleSet));
}

/**
 * Run $check with the query log on, returning its result and the number of
 * queries it took to get there.
 *
 * The count is the point: an answer alone cannot tell whether it came from the
 * database or was decided in PHP, so any test about folding has to assert both.
 *
 * @return array{mixed, int}
 */
function measureQueries(Closure $check): array
{
    $connection = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    $result = $check();

    $connection->disableQueryLog();

    return [$result, count($connection->getQueryLog())];
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

/**
 * A schema whose model names a different schema — the back-reference the registry
 * rejects on first resolution.
 */
class MismatchedBackReferenceSchema extends WarrantSchema
{
    public const model = WarrantMismatchedScopedModel::class;

    #[Ability]
    public const ABILITY_VIEW = 'view';
}

/**
 * A model that does not use HasWarrantSchema, so nothing can resolve it back to
 * a schema.
 */
class TraitlessModel extends Model
{
    protected $table = 'course_sections';

    public $incrementing = false;

    protected $keyType = 'string';
}

class TraitlessModelSchema extends WarrantSchema
{
    public const model = TraitlessModel::class;

    #[Ability]
    public const ABILITY_VIEW = 'view';
}

/**
 * Declares warrantSchema() as an instance method, the way a model written against
 * the pre-static trait did. It cannot use the trait — PHP rejects overriding an
 * abstract static with an instance method at compile time — so this stands in for
 * the shape the registry has to report clearly rather than fatal on.
 */
class NonStaticWarrantSchemaModel extends Model
{
    protected $table = 'course_sections';

    public $incrementing = false;

    protected $keyType = 'string';

    public function warrantSchema(): string
    {
        return WarrantTestSchema::class;
    }
}

/**
 * Write one rule entry or rule set back to rule text, through the
 * WarrantSyntax that would hold it on its own.
 */
function writeSyntax(IRuleEntryNode|ISchemaScopedNode $node): string
{
    return (new WarrantSyntax([$node]))->validateWithoutSchemas()->toSyntax();
}

/**
 * Write one rule entry or rule set to `?`-parameterized rule text and its
 * bindings, through the WarrantSyntax that would hold it on its own.
 */
function writeBoundSyntax(IRuleEntryNode|ISchemaScopedNode $node): BoundSyntax
{
    return (new WarrantSyntax([$node]))->validateWithoutSchemas()->toBoundSyntax();
}

/**
 * Render an expression AST to a canonical, fully-parenthesized string so two
 * trees can be compared for structural equality.
 */
function treeToString(?object $node): string
{
    return match (true) {
        $node === null => 'null',
        $node instanceof ConditionNode => $node->conditionKey->name . ($node->parameters === []
            ? ''
            : '(' . implode(',', array_map(fn ($p) => argToString($p), $node->parameters)) . ')'),
        $node instanceof CrossSchemaCanNode => $node->schemaKey === null
            ? 'can(' . $node->ability->name . ')'
            : 'can(' . $node->ability->name . ' for ' . handleToString($node) . ')',
        $node instanceof CrossSchemaConditionNode => 'check(' . treeToString($node->predicate) . ' for ' . handleToString($node) . ')',
        $node instanceof NotNode => 'not ' . treeToString($node->operand),
        $node instanceof AndNode => '(' . treeToString($node->leftSide) . ' and ' . treeToString($node->rightSide) . ')',
        $node instanceof OrNode => '(' . treeToString($node->leftSide) . ' or ' . treeToString($node->rightSide) . ')',
        $node instanceof BooleanNode => $node->value ? 'true' : 'false',
        default => throw new RuntimeException('unexpected node ' . $node::class),
    };
}

/**
 * Render an argument — a literal or binding as its value, or one of the DSL's
 * symbolic references, which must stay distinguishable (a ContextRef would
 * otherwise var_export to NULL, making every ref look alike).
 */
function argToString(INode $argument): string
{
    return match (true) {
        $argument instanceof ContextRef => '@context ' . $argument->key->name,
        $argument instanceof ColumnRef => '@column '
            . ($argument->alias === null ? '' : $argument->alias->name . '.') . $argument->column->name,
        $argument instanceof SqlRef => '@sql ' . valueOf($argument->sql),
        default => var_export(valueOf($argument), true),
    };
}

/**
 * The value a literal holds, or a binding's source gives it.
 */
function valueOf(INode $node): mixed
{
    return match (true) {
        $node instanceof LiteralNode => $node->value,
        $node instanceof BindingNode => $node->value(),
        default => throw new RuntimeException('no value for node ' . $node::class),
    };
}

/**
 * Each argument as a template or derived condition method is called with it: a
 * literal's or binding's value, and a reference as itself.
 *
 * @param list<INode> $arguments
 * @return list<mixed>
 */
function argumentValues(array $arguments): array
{
    return array_map(
        static fn (INode $argument): mixed => $argument instanceof LiteralNode || $argument instanceof BindingNode ? valueOf($argument) : $argument,
        $arguments,
    );
}

/**
 * A `with` map's entries as each key and the value its argument stands for.
 *
 * @param list<WithEntryNode> $contextMap
 * @return array<string, mixed>
 */
function withEntryValues(array $contextMap): array
{
    $values = [];

    foreach ($contextMap as $entry) {
        $values[$entry->key->name] = argumentValues([$entry->value])[0];
    }

    return $values;
}

/**
 * Every problem in $source, as an editor reports it: the syntax errors its
 * analysis found, then the mistakes validating the tree under $catalog finds,
 * with headerless rules about $schemaKey and no binding values.
 *
 * @return list<Diagnostic>
 */
function analyzedDiagnostics(string $source, ?ISchemaCatalog $catalog, ?string $schemaKey = null): array
{
    $analysis = WarrantParser::analyze($source, SyntaxProducer::editor('test.warrant', $schemaKey));

    return [...$analysis->diagnostics, ...mistakesOf($analysis->syntax, new ValidationInput($catalog, checkBindingValues: false))];
}

/**
 * A name written nowhere, as a test builds one.
 */
function nameNode(string $name): NameNode
{
    return new NameNode($name, SyntaxLocation::noProducer());
}

/**
 * $value as an argument a test builds: itself when it is already a node, else a
 * literal of it.
 */
function argumentNode(mixed $value): INode
{
    return $value instanceof INode ? $value : new LiteralNode($value, SyntaxLocation::noProducer());
}

/**
 * A `with` map a test builds, as its entries in the order given.
 *
 * @param array<string, mixed> $with
 * @return list<WithEntryNode>
 */
function withEntries(array $with): array
{
    $entries = [];

    foreach ($with as $key => $value) {
        $entries[] = new WithEntryNode(nameNode((string) $key), argumentNode($value), SyntaxLocation::noProducer());
    }

    return $entries;
}

/**
 * $value with every node in it replaced by its class and its fields, leaving out
 * where each was written, so two trees compare by what they say.
 */
function syntaxShape(mixed $value): mixed
{
    if (is_array($value)) {
        return array_map(syntaxShape(...), $value);
    }

    if (! $value instanceof INode) {
        return $value;
    }

    $fields = get_object_vars($value);
    unset($fields['location']);

    return ['node' => $value::class, ...array_map(syntaxShape(...), $fields)];
}

expect()->extend('toEqualSyntax', function (mixed $expected) {
    expect(syntaxShape($this->value))->toEqual(syntaxShape($expected));

    return $this;
});

/**
 * $syntax with a producer that validates its headerless rules as about
 * $schemaKey under $mode: an editor's for either, a schema's own rules for
 * not generic, and a template's for generic.
 */
function producedFor(WarrantSyntax $syntax, string $schemaKey, GenericMode $mode = GenericMode::Either): WarrantSyntax
{
    $syntax->source->setProducer(match ($mode) {
        GenericMode::Either => SyntaxProducer::editor('test.warrant', $schemaKey),
        GenericMode::RequireNotGeneric => SyntaxProducer::schemaRules($schemaKey, WarrantSchema::class),
        GenericMode::RequireGeneric => SyntaxProducer::ruleTemplate(
            $schemaKey,
            WarrantSchema::class,
            'template',
            'template',
            new IncludeInvocationNode(nameNode('template'), [], [], SyntaxLocation::noProducer()),
        ),
    });

    return $syntax;
}

/**
 * Render a cross-schema handle: the schema, its row-key arguments when the
 * reference is row-bound (an unbound handle has no parens at all — the
 * distinction NoRow exists to preserve, and the reason empty parens are still
 * rendered for a row-bound handle whose key takes no arguments), and any `with`
 * map.
 */
function handleToString(CrossSchemaCanNode|CrossSchemaConditionNode $node): string
{
    $out = $node->schemaKey->name;

    if ($node->isRowBound) {
        $out .= '(' . implode(', ', array_map(fn ($a) => argToString($a), $node->boundKey)) . ')';
    }

    if ($node->alias !== null) {
        $out .= ' as ' . $node->alias->name;
    }

    if ($node->contextMap !== []) {
        $entries = [];

        foreach ($node->contextMap as $entry) {
            $entries[] = $entry->key->name . ' = ' . argToString($entry->value);
        }

        $out .= ' with ' . implode(', ', $entries);
    }

    return $out;
}

// -- structur

/**
 * A catalog holding $schemas, by key, and finding any other in $behind; with
 * nothing behind, only $schemas.
 *
 * @param array<string, ISchemaVocabulary> $schemas
 */
function catalogOf(array $schemas, ?ISchemaCatalog $behind = null): ISchemaCatalog
{
    return new class($schemas, $behind) implements ISchemaCatalog
    {
        /** @param array<string, ISchemaVocabulary> $schemas */
        public function __construct(private array $schemas, private ?ISchemaCatalog $behind) {}

        public function find(string $schemaKey): ?ISchemaVocabulary
        {
            return $this->schemas[$schemaKey] ?? $this->behind?->find($schemaKey);
        }
    };
}

/**
 * $set taken through every stage of a syntax: handed over as a schema's own
 * rules, validated as written against $schema, and expanded, its bindings'
 * values put in place. Any other schema the rules name is found as the
 * application registers it.
 */
function expandedFor(RuleSetNode $set, ISchemaVocabulary $schema): ExpandedSyntax
{
    $input = new ValidationInput(catalogOf([$set->schemaKey() => $schema], new RegisteredSchemas));
    $syntax = producedFor(new WarrantSyntax([$set], $set->location->source), $set->schemaKey(), GenericMode::RequireNotGeneric);

    return ExpandedSyntax::expand($set->schemaKey(), $input, ValidSyntax::of($syntax, $input));
}

/**
 * $set validated as written against $schema, or with no schema against the
 * rules of the language alone. Throws the first mistake.
 */
function validateRuleSet(RuleSetNode $set, ?ISchemaVocabulary $schema = null): ValidSyntax
{
    return ValidSyntax::of(new WarrantSyntax([$set]), new ValidationInput(
        $schema === null ? null : catalogOf([$set->schemaKey() => $schema], new RegisteredSchemas),
    ));
}

/**
 * Every mistake $syntax makes under $input.
 *
 * @return list<Diagnostic>
 */
function mistakesOf(WarrantSyntax $syntax, ValidationInput $input): array
{
    $checked = ValidSyntax::check($syntax, $input);

    return $checked instanceof InvalidSyntax ? $checked->diagnostics : [];
}

/**
 * $syntax validated as written, every `for` body against the schema the
 * application registers under its key. Throws the first mistake.
 */
function validateRegistered(WarrantSyntax|RuleSetNode $syntax): ValidSyntax
{
    return ($syntax instanceof RuleSetNode ? new WarrantSyntax([$syntax]) : $syntax)->validate();
}
