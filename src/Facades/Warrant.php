<?php

namespace Warrant\Facades;

use Illuminate\Support\Facades\Facade;
use InvalidArgumentException;
use Warrant\Builders\WarrantConditionBuilder;
use Warrant\Builders\WarrantRuleBuilder;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantRuleNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\Validation\RuleSetValidator;
use Warrant\Rules\WarrantRuleTemplate;
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
    | Rule text of every form is read by one call, parse(), which answers with a
    | WarrantSyntax tree whose children say what the text held: a condition, a
    | rule, headless rules, or one or more `for <schema>` bodies. Nothing about
    | the text has to be known before it is read.
    |
    | A schema named in the text's own `for` header travels with the text, and
    | tooling reading the source — the language server, an editor extension — can
    | see it. Text with no header is given its schema by the caller, with
    | WarrantSyntax::scopedTo().
    |
    | A condition and a rule also have a fluent builder, reached by condition() and
    | rule(). Building a rule set from rules already in hand is
    | RuleSetNode::fromRules() and RuleSetNode::build().
    |
    | These are real statics rather than proxied @method entries: they need no user,
    | no state and nothing from the container, and WarrantManager is otherwise
    | entirely user-scoped checking and reachability.
    |
    */

    /**
     * Parse Warrant rule text of any form.
     *
     *     Warrant::parse('is_owner or is_admin')->conditionExpression()
     *     Warrant::parse('if is_self they can view')->rule()
     *     Warrant::parse('for documents { if is_self they can view }')->ruleSet()
     *     Warrant::parse('if is_self they can view')->scopedTo('documents')
     *
     * @param array<int|string, mixed> $bindings Values for `:name` / `?` placeholders.
     */
    public static function parse(string $syntax, array $bindings = []): WarrantSyntax
    {
        return WarrantSyntax::parse($syntax, $bindings);
    }

    /**
     * Parse the Warrant rule text in a file, such as a `.warrant` file.
     *
     * @param array<int|string, mixed> $bindings Values for `:name` / `?` placeholders.
     */
    public static function parseFile(string $path, array $bindings = []): WarrantSyntax
    {
        return WarrantSyntax::parseFile($path, $bindings);
    }

    /**
     * Validate every condition and ability name in one or more rule sets, each
     * against the schema registered for its own schema key, throwing on the first
     * unknown name. Runs before compilation so mistakes surface loudly rather
     * than as an empty predicate.
     *
     * To validate against a schema you already hold, construct a
     * {@see RuleSetValidator} directly rather than routing through the registry.
     *
     * @param RuleSetNode|array<int, RuleSetNode> ...$ruleSets
     */
    public static function validate(RuleSetNode|array ...$ruleSets): void
    {
        foreach ($ruleSets as $ruleSet) {
            foreach (is_array($ruleSet) ? $ruleSet : [$ruleSet] as $one) {
                if (! $one instanceof RuleSetNode) {
                    throw new InvalidArgumentException(
                        sprintf('validate expects RuleSetNode instances, got %s.', get_debug_type($one))
                    );
                }

                $schemaClass = static::registry()->resolveSchemaClassOrFail($one->schemaKey);

                (new RuleSetValidator(new $schemaClass, $one->schemaKey))->validate($one);
            }
        }
    }

    /**
     * A fluent builder for a condition expression: the `if` half of a rule, with
     * no clauses attached. It is what a schema's `#[DerivedCondition]` returns to
     * build itself from other conditions rather than emitting SQL.
     *
     *     Warrant::condition()->if('is_owner')->orIf('is_admin')
     */
    public static function condition(): WarrantConditionBuilder
    {
        return WarrantConditionBuilder::build();
    }

    /**
     * A fluent builder for one rule: conditions plus the abilities they grant or
     * deny.
     *
     *     Warrant::rule()->if('is_self')->theyCan('view', 'update')
     *
     * The builder needs no `toRule()` when it is handed to
     * {@see RuleSetNode::fromRules()}, which finishes builders itself.
     */
    public static function rule(): WarrantRuleBuilder
    {
        return WarrantRuleNode::build();
    }

    /**
     * A rule template's body: headless rule text and the values for its
     * placeholders, for a `#[RuleTemplate]` method to answer with.
     *
     *     Warrant::ruleTemplate('if not is_approved they cannot')
     *     Warrant::ruleTemplate('if is_child_of(:rel) they cannot because :why', ['rel' => $rel, 'why' => $why])
     *
     * Bindings are how a template takes a value: interpolating one into the text
     * is unsafe, and a closure denial message has no inline form at all. A body
     * with no placeholders may be returned as a plain string instead.
     *
     * The text is not parsed here. A template's clauses name no abilities, so
     * there is nothing to parse them into until the `@include` that expands it
     * supplies them.
     *
     * @param array<int|string, mixed> $bindings Values for `:name` / `?` placeholders.
     */
    public static function ruleTemplate(string $syntax, array $bindings = []): WarrantRuleTemplate
    {
        return new WarrantRuleTemplate($syntax, $bindings);
    }
}
