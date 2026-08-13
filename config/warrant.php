<?php

return [
    /*
     * The rule resolver: a class implementing Warrant\RuleResolver.
     *
     * Warrant ships no default — you must provide one. It maps the current user
     * to the WarrantRuleSet that governs their access to an entity, built from
     * wherever your access rules live: role/permission tables, JWT claims, a
     * remote service, config, etc.
     */
    'rule_resolver' => null,

    /*
     * The Warrant schemas registered with the application. Registration is
     * explicit: every schema that governs a resource must be listed here. A
     * schema that is not listed is unknown to access checks and lookups.
     */
    'schemas' => [
        // App\Schemas\PostSchema::class,
    ],

    /*
     * Reserved identifier namespace for the table aliases Warrant generates when a
     * condition joins additional tables (see Warrant\Schema\Conditions\AliasFactory).
     * Every generated alias starts with this prefix, which keeps them from colliding
     * with your own tables and aliases. Your application must not name tables or
     * aliases beginning with this string. Override it only if the default is not
     * distinctive enough for your schema.
     */
    'alias_prefix' => '__warrant_',
];
