<?php

namespace Warrant\Facades;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Warrant\Builders\WarrantConditionBuilder;
use Warrant\Builders\WarrantRuleBuilder;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\WarrantParser;
use Warrant\Rules\RuleSetGroup;
use Warrant\Rules\WarrantRule;
use Warrant\Rules\WarrantRuleSet;
use Warrant\Schema\WarrantSchema;
use Warrant\WarrantManager;

/**
 * @method static \Warrant\Registry\SchemaRegistry registry()
 * @method static \Warrant\Guard\WarrantGuard guard(\Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static void flush(\Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static \Warrant\Guard\WarrantGuardForSchema forSchema(\Illuminate\Database\Eloquent\Model|\Warrant\Schema\WarrantSchema|string $schema, \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static bool can(string|array $abilities, \Illuminate\Database\Eloquent\Model|string|array $target, array $context = [], \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static bool canAny(string|array $abilities, \Illuminate\Database\Eloquent\Model|string|array $target, array $context = [], \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static bool cannot(string|array $abilities, \Illuminate\Database\Eloquent\Model|string|array $target, array $context = [], \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static void authorize(string|array $abilities, \Illuminate\Database\Eloquent\Model|string|array $target, array $context = [], \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static void authorizeAny(string|array $abilities, \Illuminate\Database\Eloquent\Model|string|array $target, array $context = [], \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static array abilities(\Illuminate\Database\Eloquent\Model|string|array $target, array $context = [], \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static \Warrant\Reachability reachabilityOf(\Illuminate\Database\Eloquent\Model|\Warrant\Schema\WarrantSchema|string $schema, string $ability, \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static bool couldEverHave(\Illuminate\Database\Eloquent\Model|\Warrant\Schema\WarrantSchema|string $schema, string|array $abilities, \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static bool couldEverHaveAny(\Illuminate\Database\Eloquent\Model|\Warrant\Schema\WarrantSchema|string $schema, string|array $abilities, \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static bool alwaysHas(\Illuminate\Database\Eloquent\Model|\Warrant\Schema\WarrantSchema|string $schema, string|array $abilities, \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static bool alwaysHasAny(\Illuminate\Database\Eloquent\Model|\Warrant\Schema\WarrantSchema|string $schema, string|array $abilities, \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static bool neverHas(\Illuminate\Database\Eloquent\Model|\Warrant\Schema\WarrantSchema|string $schema, string|array $abilities, \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static bool neverHasAny(\Illuminate\Database\Eloquent\Model|\Warrant\Schema\WarrantSchema|string $schema, string|array $abilities, \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static array possibleAbilities(\Illuminate\Database\Eloquent\Model|\Warrant\Schema\WarrantSchema|string $schema, \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static array guaranteedAbilities(\Illuminate\Database\Eloquent\Model|\Warrant\Schema\WarrantSchema|string $schema, \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static array impossibleAbilities(\Illuminate\Database\Eloquent\Model|\Warrant\Schema\WarrantSchema|string $schema, \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 *
 * @see \Warrant\WarrantManager
 */
class Warrant extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return WarrantManager::class;
    }

    /*
    |--------------------------------------------------------------------------
    | Rule authoring
    |--------------------------------------------------------------------------
    |
    | The four constructs an author writes — a condition, a rule, a rule set, a
    | group — reachable from one place, each parsing Warrant syntax and each taking
    | exactly the parameters its parsing constructor takes.
    |
    | That parameter symmetry is the point rather than a courtesy. A schema named in
    | the string's own `for` header travels with the string; a schema passed as a
    | separate PHP argument does not, and tooling reading the source — the language
    | server, an editor extension — can only see the former. Writing
    | `Warrant::ruleSet('for documents { ... }')` is therefore what makes the rules
    | inside checkable; the `$schema` argument remains for the cases where the
    | string genuinely has no header, and a headerless string is simply left
    | unvalidated.
    |
    | Two of them also answer with a builder when handed nothing. A condition and a
    | rule *are* their fluent chain, so an empty call is meaningful; a rule set and
    | a group are collections, so their syntax is required. Building from rule or
    | rule-set *values* rather than syntax is not duplicated here — that is
    | WarrantRuleSet::fromRules(), WarrantRuleSet::build() and
    | RuleSetGroup::fromRuleSets(), which take builders as readily as finished
    | values.
    |
    | These are real statics rather than proxied @method entries: they need no user,
    | no state and nothing from the container, and WarrantManager is otherwise
    | entirely user-scoped checking and reachability.
    |
    */

    /**
     * A condition expression: the `if` half of a rule, with no clauses attached.
     *
     * With no argument you get the builder, which is what a schema's own condition
     * returns when it derives itself from other conditions rather than emitting SQL.
     * With syntax you get the parsed expression tree.
     *
     *     Warrant::condition()->if('is_owner')->orIf('is_admin')
     *     Warrant::condition('is_owner or is_admin')
     *     Warrant::condition('for documents is_owner or is_admin')
     *
     * A `for` header is accepted and discarded — an expression has no schema field
     * to carry it. It is there so that tooling reading this string knows which
     * schema's conditions to resolve `is_owner` against.
     *
     * @param array<int|string, mixed> $bindings Values for `:name` / `?` placeholders.
     * @return ($syntax is null ? WarrantConditionBuilder : IBooleanExpressionNode)
     */
    public static function condition(?string $syntax = null, array $bindings = []): IBooleanExpressionNode|WarrantConditionBuilder
    {
        return $syntax === null
            ? WarrantConditionBuilder::build()
            : WarrantParser::parseConditionExpression($syntax, $bindings);
    }

    /**
     * One rule: conditions plus the abilities they grant or deny.
     *
     *     Warrant::rule()->if('is_self')->theyCan('view', 'update')
     *     Warrant::rule('for documents if is_self they can view, update')
     *
     * The builder form needs no `toRule()` when it is handed to
     * {@see WarrantRuleSet::fromRules()}, which finishes builders itself.
     *
     * @param Model|WarrantSchema|string|null $schema The rule's target schema, for a
     *   string with no `for` header. A header and an argument that disagree are an
     *   error; prefer the header, which travels with the string.
     * @param array<int|string, mixed> $bindings Values for `:name` / `?` placeholders.
     * @return ($syntax is null ? WarrantRuleBuilder : WarrantRule)
     */
    public static function rule(
        ?string $syntax = null,
        Model|WarrantSchema|string|null $schema = null,
        array $bindings = [],
    ): WarrantRule|WarrantRuleBuilder {
        return $syntax === null
            ? WarrantRule::build()
            : WarrantRule::fromSyntax($syntax, $schema, $bindings);
    }

    /**
     * A rule set: every rule that applies to one schema, with or without the
     * `{ ... }` block form.
     *
     *     Warrant::ruleSet('for documents { if is_self they can view  they cannot delete }')
     *     Warrant::ruleSet('if is_self they can view', 'documents')
     *
     * Syntax is required: unlike a condition or a rule, a rule set has no builder to
     * hand back. To assemble one from rules you already hold, use
     * {@see WarrantRuleSet::fromRules()} or {@see WarrantRuleSet::build()}.
     *
     * @param Model|WarrantSchema|string|null $schema The target schema, for a string
     *   with no `for` header.
     * @param array<int|string, mixed> $bindings Values for `:name` / `?` placeholders.
     */
    public static function ruleSet(
        string $syntax,
        Model|WarrantSchema|string|null $schema = null,
        array $bindings = [],
    ): WarrantRuleSet {
        return WarrantRuleSet::fromSyntax($syntax, $schema, $bindings);
    }

    /**
     * A group: the rule sets for several schemas at once, as `for <schema> { ... }`
     * blocks. Two blocks naming the same schema merge, in the order they appear.
     *
     *     Warrant::group('for documents { they can view } for timesheets { they can edit }')
     *
     * There is no `$schema` parameter and there could not be one — each block names
     * its own. Read a whole `.warrant` file with {@see RuleSetGroup::fromFile()},
     * which reports the path when it cannot be read; assemble one from rule sets you
     * already hold with {@see RuleSetGroup::fromRuleSets()}.
     *
     * @param array<int|string, mixed> $bindings Values for `:name` / `?` placeholders.
     */
    public static function group(string $syntax, array $bindings = []): RuleSetGroup
    {
        return RuleSetGroup::fromSyntax($syntax, $bindings);
    }
}
