<?php

return [
    /*
     * The global rule provider: a class implementing Warrant\Rules\RuleProvider.
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
     * When true, Warrant registers a Gate::before hook so its abilities resolve
     * through Laravel's Gate — $user->can(), @can, Gate::authorize, and the
     * `can:` route middleware. Abilities that no registered schema declares fall
     * through untouched to your own policies. Set false to opt out entirely.
     */
    'register_gate' => true,
];
