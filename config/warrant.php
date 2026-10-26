<?php

return [
    /*
     * The global rule provider: a class implementing Warrant\Rules\IRuleProvider.
     *
     * Optional. It maps the current user to the rule set that governs their
     * access to an entity, built from wherever your access rules live:
     * role/permission tables, JWT claims, a remote service, config, etc. Its
     * rules are merged with each schema's own rules(); left null, a schema's
     * rules() alone govern access to it.
     */
    'rule_provider' => null,

    /*
     * The Warrant schemas registered with the application, keyed by schema key.
     *
     * The array key *is* the schema key: the short, stable identifier that
     * appears in rule strings (`for posts { ... }`, `can(view for posts)`,
     * `@column posts.author_id`) and in the RuleProviderContext handed to your
     * rule provider. It is the only place a schema key is declared, so treat it
     * like a database identifier — renaming one changes the meaning of every
     * rule string that already references it.
     *
     * Registration is explicit: a schema that is not listed here is unknown to
     * access checks and lookups. Nothing in this array is loaded until a schema
     * is actually used, so listing hundreds of schemas costs one array of
     * strings, not hundreds of class loads.
     */
    'schemas' => [
        // 'posts' => App\Schemas\PostSchema::class,
    ],

    /*
     * The global schemas: classes extending Warrant\Schema\Types\Classes\GlobalSchema,
     * whose global conditions, derived conditions and rule templates any rule may
     * use without naming them.
     *
     * The order is the order they are searched in, top down: where two declare
     * the same name, the one listed first is used. A schema's own constructs come
     * before all of these. A global schema's own constructs do not: the names in
     * its templates and derived conditions are looked up in this list alone.
     *
     * A global schema has no key, because no rule names it.
     */
    'global' => [
        // App\Schemas\TenancySchema::class,
    ],

    /*
     * When true, Warrant registers a Gate::before hook so its abilities resolve
     * through Laravel's Gate — $user->can(), @can, Gate::authorize, and the
     * `can:` route middleware. Abilities that no registered schema declares fall
     * through untouched to your own policies. Set false to opt out entirely.
     */
    'register_gate' => true,
];
