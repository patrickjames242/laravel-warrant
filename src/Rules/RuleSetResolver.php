<?php

namespace Warrant\Rules;

use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use Warrant\Builders\WarrantRuleBuilder;
use Warrant\DSL\Parsing\ASTNodes\ExpandedSyntax;
use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\ValidSyntax;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\BoundSyntax;
use Warrant\DSL\Parsing\SyntaxProducer;
use Warrant\DSL\Parsing\SyntaxProducerKind;
use Warrant\DSL\Parsing\WarrantParser;
use Warrant\DSL\Validation\ValidationInput;
use Warrant\Registry\SchemaRegistry;
use Warrant\Schema\Types\Interfaces\IModelSchema;

/**
 * The rules that govern a schema for a user, or for no particular user: what the
 * schema's own `rules()` and the bound {@see IRuleProvider}, if any, answer with,
 * each validated as written ({@see ValidSyntax}) and then expanded together into
 * one {@see ExpandedSyntax}, the schema's first. The provider may answer with
 * rules for any schema, of which only this schema's are kept; the schema's own
 * rules must all be for it.
 *
 * Each (schema, user) is resolved once and memoized for the life of the
 * resolver, which is the manager's: under PHP-FPM, one request. Long-lived
 * runtimes (Octane, queue workers, tinker) reuse the container across requests
 * and jobs, so the service provider flushes at those boundaries; see
 * {@see flush()}.
 */
final class RuleSetResolver
{
    /**
     * The cap on users with memoized rule sets. A long-lived process that sweeps
     * many users (a queue job checking access for every account) would otherwise
     * grow the memo without bound. Past the cap the memo is dropped whole rather
     * than evicted entry by entry: rule sets are cheap to resolve again, and a
     * predictable reset beats LRU bookkeeping on the check hot path.
     */
    private const MAX_MEMOIZED_USERS = 1000;

    /**
     * The memo key for rules resolved for no particular user. Every user's key
     * starts with its class name, so none is empty before the separator.
     */
    private const NO_USER = "\0";

    /** @var array<string, array<string, ExpandedSyntax>> Keyed by user, then by schema key. */
    private array $ruleSets = [];

    /**
     * @var array<string, Authenticatable> The users memoized by object identity,
     *   held so their object ids cannot be recycled while their entries exist.
     */
    private array $usersByObject = [];

    public function __construct(private readonly SchemaRegistry $registry)
    {
    }

    /**
     * The rules for the schema registered under $schemaKey, resolved for exactly
     * $user, validated and expanded: rules alone, every ability block opened up,
     * every include replaced by its template's rules and every derived condition
     * by its expression.
     *
     * A null $user means no particular user, never the current one: it is handed
     * to the schema and the provider as null, and its rules are memoized apart
     * from every user's.
     */
    public function ruleSet(string $schemaKey, ?Authenticatable $user): ExpandedSyntax
    {
        $key = $user === null ? self::NO_USER : self::userKey($user);

        if (isset($this->ruleSets[$key][$schemaKey])) {
            return $this->ruleSets[$key][$schemaKey];
        }

        if (! isset($this->ruleSets[$key]) && count($this->ruleSets) >= self::MAX_MEMOIZED_USERS) {
            $this->ruleSets = [];
            $this->usersByObject = [];
        }

        if ($user !== null && ! is_scalar($user->getAuthIdentifier())) {
            $this->usersByObject[$key] = $user;
        }

        return $this->ruleSets[$key][$schemaKey] = $this->resolve($schemaKey, $user);
    }

    /**
     * Drop memoized rule sets: $user's alone, or with no user, every one, the
     * rules resolved for no particular user included.
     *
     * `$user` is not defaulted to the authenticated user. A bare `flush()` means
     * "everyone", since narrowing it to the current user would make the common
     * case, a rule change affecting many users, quietly wrong.
     *
     * Call this whenever rules change within a single request or test: the memo
     * is keyed by user identity, not by rule content, so it cannot notice a role
     * change on its own. Flushing a user whose identifier is absent or
     * non-scalar drops only the instance passed, since such a user is memoized by
     * object identity ({@see userKey()}).
     */
    public function flush(?Authenticatable $user = null): void
    {
        if ($user === null) {
            $this->ruleSets = [];
            $this->usersByObject = [];

            return;
        }

        $key = self::userKey($user);

        unset($this->ruleSets[$key], $this->usersByObject[$key]);
    }

    /**
     * Resolve the rules for $schemaKey and $user afresh.
     */
    private function resolve(string $schemaKey, ?Authenticatable $user): ExpandedSyntax
    {
        $schema = $this->registry->resolveOrFail($schemaKey)->schema;

        $context = new RuleProviderContext(
            schemaKey: $schemaKey,
            schema: $schema::class,
            user: $user,
            model: $schema instanceof IModelSchema ? $schema::model() : null,
        );

        $answers = $this->validated($schema->rules($context), SyntaxProducer::schemaRules($schemaKey, $schema::class));

        if (app()->bound(IRuleProvider::class)) {
            $provider = app(IRuleProvider::class);

            array_push($answers, ...$this->validated($provider->rules($context), SyntaxProducer::ruleProvider($schemaKey, $provider::class)));
        }

        return ExpandedSyntax::expand($schemaKey, new ValidationInput($this->registry), ...$answers);
    }

    /**
     * The rules $producer answered with, each validated as written. They may
     * come in any form: a {@see RuleSetNode}, a rule entry, a
     * {@see WarrantRuleBuilder}, rule text as a string, {@see BoundSyntax} or
     * {@see WarrantSyntax}, or an iterable of any of these. Text is parsed with
     * $producer. A builder is resolved in a source of its own. Anything
     * else is a syntax rooted at the source it was made in, the node's own, and
     * $producer is set on that source, so every node made in it says what
     * handed it over. A schema's own rules must all be for that
     * schema.
     *
     * @return list<ValidSyntax>
     */
    private function validated(mixed $rules, SyntaxProducer $producer): array
    {
        if (is_iterable($rules) && ! $rules instanceof WarrantSyntax) {
            $answers = [];

            foreach ($rules as $rule) {
                array_push($answers, ...$this->validated($rule, $producer));
            }

            return $answers;
        }

        if ($rules instanceof WarrantRuleBuilder) {
            $rules = $rules->toRule();
        }

        if ($rules instanceof IRuleEntryNode || $rules instanceof RuleSetNode) {
            $rules = new WarrantSyntax([$rules], $rules->location->source);
        }

        if ($rules instanceof WarrantSyntax) {
            $rules->source->setProducer($producer);
        }

        $syntax = match (true) {
            $rules instanceof WarrantSyntax => $rules,
            is_string($rules), $rules instanceof BoundSyntax => WarrantParser::parse($rules, $producer),
            default => throw new InvalidArgumentException(sprintf(
                '%s returned %s; expected a rule set, a rule entry, a rule builder, rule text, or an iterable of them.',
                $producer->describe(),
                get_debug_type($rules),
            )),
        };

        /* A schema's own rules are all about it. The names in another schema's
           rule set would otherwise be caught only when one happens to be missing
           from this schema, and silently left out when both share the
           vocabulary. */
        if ($producer->kind === SyntaxProducerKind::SchemaRules) {
            foreach ($syntax->isSchemaScoped() ? $syntax->schemaKeys() : [] as $named) {
                if ($named !== $producer->schemaKey) {
                    throw new InvalidArgumentException(sprintf(
                        '%s returned a rule set for schema [%s]; a schema\'s own rules are all for it.',
                        $producer->describe(),
                        $named,
                    ));
                }
            }
        }

        return [ValidSyntax::of($syntax, new ValidationInput($this->registry))];
    }

    /**
     * The memo key for a user.
     *
     * Two instances of the same persisted user share their rule sets: rules
     * follow the identifier, not the object. A user whose identifier is absent
     * or non-scalar (an unsaved model) falls back to object identity, which is
     * safe because the memo holds that instance ({@see $usersByObject}): its
     * object id cannot be recycled while the entry that uses it exists.
     */
    private static function userKey(Authenticatable $user): string
    {
        $identifier = $user->getAuthIdentifier();

        return is_scalar($identifier)
            ? $user::class."\0".$identifier
            : $user::class."\0#".spl_object_id($user);
    }
}
