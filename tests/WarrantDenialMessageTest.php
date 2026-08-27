<?php


use Warrant\Facades\Warrant;
require_once __DIR__.'/Support/TestSupport.php';

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Warrant\Ability;
use Warrant\AbilityMatchMode;
use Warrant\GlobalCondition;
use Warrant\RuleSyntaxTree\Parsing\WarrantParser;
use Warrant\RuleSyntaxTree\WarrantRule;
use Warrant\RuleSyntaxTree\WarrantRuleSet;
use Warrant\Schema\Conditions\GlobalConditionContext;
use Warrant\Schema\WarrantSchema;
use Warrant\WarrantAuthorizationException;
use Warrant\WarrantDenialContext;
use Warrant\WarrantUngrantedContext;

// -- fixtures -----------------------------------------------------------------

class DenialCustomException extends RuntimeException {}

/** A schema whose implicit rules carry a message-bearing cannot. */
class DenialImplicitSchema extends WarrantTestSchema
{
    public function implicitRules(): array
    {
        return [
            WarrantRule::build()->theyCannotBecause('update', 'implicit locked')->toRule(),
        ];
    }
}

/** A model with a global scope that hides the `other-section` row. */
class DenialScopedModel extends Model
{
    protected $table = 'course_sections';

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::addGlobalScope('hide', fn ($q) => $q->where('course_sections.id', '!=', 'other-section'));
    }
}

class DenialScopedSchema extends WarrantTestSchema
{
    public const model = DenialScopedModel::class;
}

/** A schema with a global condition that reads the context bag. */
class DenialContextSchema extends WarrantSchema
{
    public const model = WarrantTestModel::class;

    #[Ability]
    public const UPDATE = 'update';

    public const REGION = 'region';

    #[GlobalCondition]
    public function regionLocked(GlobalConditionContext $c): bool
    {
        return ($c->context['region'] ?? null) === 'eu';
    }
}

/** A schema whose ungranted hook returns a string echoing the gate + subset. */
class DenialUngrantedSchema extends WarrantTestSchema
{
    public function ungrantedDenialMessage(WarrantUngrantedContext $c): string|Throwable|null
    {
        return 'Not permitted: '.implode(',', $c->ungrantedAbilities).' ('.$c->gate->matchMode->name.')';
    }
}

/** A schema whose ungranted hook throws a custom exception. */
class DenialUngrantedThrowSchema extends WarrantTestSchema
{
    public function ungrantedDenialMessage(WarrantUngrantedContext $c): string|Throwable|null
    {
        return new DenialCustomException('no grant for '.$c->ungrantedAbilities[0]);
    }
}

/** A schema that catches message-less forbids with a string. */
class DenialForbiddenSchema extends WarrantTestSchema
{
    public function forbiddenDenialMessage(WarrantDenialContext $c): string|Throwable|null
    {
        return 'Forbidden: '.implode(',', $c->deniedAbilities);
    }
}

/** A schema whose forbidden hook throws a custom exception. */
class DenialForbiddenThrowSchema extends WarrantTestSchema
{
    public function forbiddenDenialMessage(WarrantDenialContext $c): string|Throwable|null
    {
        return new DenialCustomException('forbidden '.$c->deniedAbilities[0]);
    }
}

/** A schema that catches both forbidden and ungranted denials. */
class DenialBothSchema extends WarrantTestSchema
{
    public function forbiddenDenialMessage(WarrantDenialContext $c): string|Throwable|null
    {
        return 'forbidden:'.implode(',', $c->deniedAbilities);
    }

    public function ungrantedDenialMessage(WarrantUngrantedContext $c): string|Throwable|null
    {
        return 'ungranted:'.implode(',', $c->ungrantedAbilities);
    }
}

// -- helpers ------------------------------------------------------------------

function seedDenialSections(): void
{
    Schema::create('course_sections', fn ($table) => $table->string('id'));

    DB::table('course_sections')->insert([
        ['id' => 'teacher:teacher-role'],
        ['id' => 'other-section'],
    ]);
}

/**
 * @param  array<int, WarrantRule|\Warrant\RuleSyntaxTree\WarrantRuleBuilder>  $rules
 */
function bindDenialRules(array $rules, string $schema = WarrantTestSchema::class): void
{
    bindWarrantRuleSet(WarrantRuleSet::fromRules($schema, $rules));
}

// -- string & closure messages ------------------------------------------------

it('surfaces a string message from a matching cannot rule', function () {
    seedDenialSections();
    bindDenialRules([
        WarrantRule::build()->theyCan('update')->toRule(),
        WarrantRule::build()->if('is_teacher')
            ->theyCannotBecause('update', 'This section is archived and can no longer be edited.')->toRule(),
    ]);

    $call = fn () => Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(WarrantTestSchema::class)->authorize('update', 'teacher:teacher-role');

    expect($call)->toThrow(WarrantAuthorizationException::class, 'This section is archived and can no longer be edited.');
    // Extends Laravel's AuthorizationException so the framework renders it as 403.
    expect($call)->toThrow(AuthorizationException::class);
});

it('surfaces a string returned from a closure message', function () {
    seedDenialSections();
    bindDenialRules([
        WarrantRule::build()->theyCan('update')->toRule(),
        WarrantRule::build()->if('is_teacher')
            ->theyCannotBecause('update', fn (WarrantDenialContext $c) => "You cannot {$c->deniedAbilities[0]} {$c->target->getKey()}.")
            ->toRule(),
    ]);

    expect(fn () => Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(WarrantTestSchema::class)->authorize('update', 'teacher:teacher-role'))
        ->toThrow(WarrantAuthorizationException::class, 'You cannot update teacher:teacher-role.');
});

it('throws a custom Throwable returned from a closure message as-is', function () {
    seedDenialSections();
    bindDenialRules([
        WarrantRule::build()->theyCan('update')->toRule(),
        WarrantRule::build()->if('is_teacher')
            ->theyCannotBecause('update', fn (WarrantDenialContext $c) => new DenialCustomException('custom denial'))
            ->toRule(),
    ]);

    expect(fn () => Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(WarrantTestSchema::class)->authorize('update', 'teacher:teacher-role'))
        ->toThrow(DenialCustomException::class, 'custom denial');
});

it('passes the resolved target model and ability into the closure context', function () {
    seedDenialSections();

    $captured = null;
    bindDenialRules([
        WarrantRule::build()->theyCan('update')->toRule(),
        WarrantRule::build()
            ->theyCannotBecause('update', function (WarrantDenialContext $c) use (&$captured) {
                $captured = $c;

                return 'denied';
            })->toRule(),
    ]);

    try {
        Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(WarrantTestSchema::class)->authorize('update', 'teacher:teacher-role');
    } catch (WarrantAuthorizationException) {
        // expected
    }

    expect($captured)->toBeInstanceOf(WarrantDenialContext::class);
    expect($captured->target)->toBeInstanceOf(WarrantTestModel::class);
    expect($captured->target->getKey())->toBe('teacher:teacher-role');
    expect($captured->gate->abilities)->toBe(['update']);
    expect($captured->gate->matchMode)->toBe(AbilityMatchMode::ALL);
    expect($captured->deniedAbilities)->toBe(['update']);
    expect($captured->rule)->toBeInstanceOf(WarrantRule::class);
    expect($captured->schema)->toBe(WarrantTestSchema::class);
});

// -- grant path ---------------------------------------------------------------

it('returns without throwing when access is granted', function () {
    seedDenialSections();
    bindDenialRules([
        WarrantRule::build()->if('is_teacher')->theyCan('view')->toRule(),
    ]);

    Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(WarrantTestSchema::class)->authorize('view', 'teacher:teacher-role');
})->throwsNoExceptions();

it('adds no queries on the grant path beyond the boolean check', function () {
    seedDenialSections();
    bindDenialRules([
        WarrantRule::build()->if('is_teacher')->theyCan('view')->toRule(),
    ]);
    $user = makeWarrantTestUser('teacher-role');

    DB::connection()->enableQueryLog();
    Warrant::guard($user)->forSchema(WarrantTestSchema::class)->can('view', 'teacher:teacher-role');
    $boolQueries = count(DB::connection()->getQueryLog());

    DB::connection()->flushQueryLog();
    Warrant::guard($user)->forSchema(WarrantTestSchema::class)->authorize('view', 'teacher:teacher-role');
    $authorizeQueries = count(DB::connection()->getQueryLog());

    expect($authorizeQueries)->toBe($boolQueries);
});

// -- attribution: cannot only -------------------------------------------------

it('falls back to a generic exception when denial is only "no grant"', function () {
    seedDenialSections();
    // No `can` rule grants view; the message-bearing cannot targets a different row.
    bindDenialRules([
        WarrantRule::build()->if('is_teacher')->theyCannotBecause('view', 'teacher blocked')->toRule(),
    ]);

    // `other-section` is not a teacher row: no grant, and the cannot does not match.
    expect(fn () => Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(WarrantTestSchema::class)->authorize('view', 'other-section'))
        ->toThrow(WarrantAuthorizationException::class, 'This action is unauthorized.');
});

it('fires an unconditional cannot message when the row exists', function () {
    seedDenialSections();
    bindDenialRules([
        WarrantRule::build()->theyCannotBecause('update', 'never editable')->toRule(),
    ]);

    expect(fn () => Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(WarrantTestSchema::class)->authorize('update', 'teacher:teacher-role'))
        ->toThrow(WarrantAuthorizationException::class, 'never editable');
});

it('fires a targeted cannot message only for the matching row', function () {
    seedDenialSections();
    bindDenialRules([
        WarrantRule::build()->theyCan('update')->toRule(),
        WarrantRule::build()->if('is_teacher')->theyCannotBecause('update', 'teacher row locked')->toRule(),
    ]);
    $user = makeWarrantTestUser('teacher-role');

    // Matching row: denied with message.
    expect(fn () => Warrant::guard($user)->forSchema(WarrantTestSchema::class)->authorize('update', 'teacher:teacher-role'))
        ->toThrow(WarrantAuthorizationException::class, 'teacher row locked');

    // Non-matching row: the grant applies, so access is allowed.
    Warrant::guard($user)->forSchema(WarrantTestSchema::class)->authorize('update', 'other-section');
});

it('fires a global cannot message', function () {
    seedDenialSections();
    bindDenialRules([
        WarrantRule::build()->theyCan('update')->toRule(),
        WarrantRule::build()->if('is_advisor')->theyCannotBecause('update', 'advisors cannot edit')->toRule(),
    ]);

    expect(fn () => Warrant::guard(makeWarrantTestUser('advisor'))->forSchema(WarrantTestSchema::class)->authorize('update', 'teacher:teacher-role'))
        ->toThrow(WarrantAuthorizationException::class, 'advisors cannot edit');
});

it('falls back to generic when the denying cannot has no message', function () {
    seedDenialSections();
    bindDenialRules([
        WarrantRule::build()->theyCan('update')->toRule(),
        WarrantRule::build()->theyCannot('update')->toRule(),                        // denies, no message
        WarrantRule::build()->if('is_advisor')->theyCannotBecause('update', 'advisor only')->toRule(),
    ]);

    // User is not an advisor, so the only message-bearing cannot does not match.
    expect(fn () => Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(WarrantTestSchema::class)->authorize('update', 'teacher:teacher-role'))
        ->toThrow(WarrantAuthorizationException::class, 'This action is unauthorized.');
});

// -- ordering -----------------------------------------------------------------

it('surfaces the earliest message-bearing cannot when several match', function () {
    seedDenialSections();
    bindDenialRules([
        WarrantRule::build()->if('is_teacher')->theyCannotBecause('update', 'first')->toRule(),
        WarrantRule::build()->theyCannotBecause('update', 'second')->toRule(),
    ]);

    expect(fn () => Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(WarrantTestSchema::class)->authorize('update', 'teacher:teacher-role'))
        ->toThrow(WarrantAuthorizationException::class, 'first');
});

it('skips an earlier matching cannot that has no message', function () {
    seedDenialSections();
    bindDenialRules([
        WarrantRule::build()->theyCannot('update')->toRule(),                                    // matches, no message
        WarrantRule::build()->if('is_teacher')->theyCannotBecause('update', 'teacher msg')->toRule(),
    ]);

    expect(fn () => Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(WarrantTestSchema::class)->authorize('update', 'teacher:teacher-role'))
        ->toThrow(WarrantAuthorizationException::class, 'teacher msg');
});

it('lets an implicit-rule message win over a resolver-rule message', function () {
    seedDenialSections();
    bindDenialRules([
        WarrantRule::build()->theyCan('update')->toRule(),
        WarrantRule::build()->if('is_teacher')->theyCannotBecause('update', 'resolver msg')->toRule(),
    ], DenialImplicitSchema::class);

    // Implicit rules are prepended, so their unconditional cannot is diagnosed first.
    expect(fn () => Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(DenialImplicitSchema::class)->authorize('update', 'teacher:teacher-role'))
        ->toThrow(WarrantAuthorizationException::class, 'implicit locked');
});

// -- scope isolation & existence ----------------------------------------------

it('diagnoses a row hidden by a model global scope (warden operates without scopes)', function () {
    seedDenialSections();
    bindDenialRules([
        WarrantRule::build()->theyCannotBecause('view', 'blocked')->toRule(),
    ], DenialScopedSchema::class);

    // `other-section` is hidden by the model's global scope, but warden's
    // single-target check and its diagnostic both run on the base query without
    // scopes (getQuery() / newQueryWithoutScopes()), so the rule still governs
    // the row and its message is surfaced. This locks in that check and diagnosis
    // agree — the diagnostic never disagrees with the decision it explains.
    expect(fn () => Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(DenialScopedSchema::class)->authorize('view', 'other-section'))
        ->toThrow(WarrantAuthorizationException::class, 'blocked');
});

it('falls back to generic when the target row does not exist', function () {
    seedDenialSections();
    bindDenialRules([
        WarrantRule::build()->theyCannotBecause('update', 'never editable')->toRule(),
    ]);

    expect(fn () => Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(WarrantTestSchema::class)->authorize('update', 'no-such-row'))
        ->toThrow(WarrantAuthorizationException::class, 'This action is unauthorized.');
});

// -- match modes --------------------------------------------------------------

it('diagnoses the first denied ability under ALL', function () {
    seedDenialSections();
    bindDenialRules([
        WarrantRule::build()->theyCan('view')->toRule(),
        WarrantRule::build()->theyCannotBecause('update', 'no update')->toRule(),
    ]);

    // view is granted, update is denied -> ALL fails on update.
    expect(fn () => Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(WarrantTestSchema::class)->authorize(['update', 'view'], 'teacher:teacher-role'))
        ->toThrow(WarrantAuthorizationException::class, 'no update');
});

it('diagnoses the first denied ability under ANY', function () {
    seedDenialSections();
    bindDenialRules([
        WarrantRule::build()->theyCannotBecause('update', 'no update')->toRule(),
        WarrantRule::build()->theyCannotBecause('archive', 'no archive')->toRule(),
    ]);

    // Neither is grantable -> ANY fails; the first requested denied ability wins.
    expect(fn () => Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(WarrantTestSchema::class)->authorizeAny(['update', 'archive'], 'teacher:teacher-role'))
        ->toThrow(WarrantAuthorizationException::class, 'no update');
});

// -- context threading --------------------------------------------------------

it('threads the effective context into the diagnostic', function () {
    seedDenialSections();
    bindDenialRules([
        WarrantRule::build()->theyCan('update')->toRule(),
        WarrantRule::build()->if('region_locked')->theyCannotBecause('update', 'EU is locked')->toRule(),
    ], DenialContextSchema::class);
    $user = makeWarrantTestUser('teacher-role');

    // With region=eu the cannot fires and its message must survive diagnosis.
    expect(fn () => Warrant::guard($user)->forSchema(DenialContextSchema::class)->authorize('update', 'other-section', ['region' => 'eu']))
        ->toThrow(WarrantAuthorizationException::class, 'EU is locked');

    // With a different region the grant applies and access is allowed.
    Warrant::guard($user)->forSchema(DenialContextSchema::class)->authorize('update', 'other-section', ['region' => 'us']);
});

// -- no-target diagnosis ------------------------------------------------------

it('diagnoses a no-target denial from a global cannot rule', function () {
    seedDenialSections();
    bindDenialRules([
        WarrantRule::build()->theyCan('publish')->toRule(),
        WarrantRule::build()->if('is_advisor')->theyCannotBecause('publish', 'advisors cannot publish')->toRule(),
    ]);

    // No target: the global `is_advisor` cannot is the cause and its message survives.
    expect(fn () => Warrant::guard(makeWarrantTestUser('advisor'))->forSchema(WarrantTestSchema::class)->authorize('publish', null))
        ->toThrow(WarrantAuthorizationException::class, 'advisors cannot publish');
});

it('diagnoses a no-target denial from an unconditional cannot rule', function () {
    seedDenialSections();
    bindDenialRules([
        WarrantRule::build()->theyCan('publish')->toRule(),
        WarrantRule::build()->theyCannotBecause('publish', 'publishing disabled')->toRule(),
    ]);

    expect(fn () => Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(WarrantTestSchema::class)->authorize('publish', null))
        ->toThrow(WarrantAuthorizationException::class, 'publishing disabled');
});

it('cannot attribute a no-target denial to a targeted-only cannot', function () {
    seedDenialSections();
    // The only message-bearing cannot is targeted; without a row it cannot fire,
    // so a no-target denial falls back to the generic exception.
    bindDenialRules([
        WarrantRule::build()->if('is_teacher')->theyCannotBecause('publish', 'teacher blocked')->toRule(),
    ]);

    expect(fn () => Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(WarrantTestSchema::class)->authorize('publish', null))
        ->toThrow(WarrantAuthorizationException::class, 'This action is unauthorized.');
});

// -- attaching a message to any rule (withDenialMessage wither) ---------------

it('attaches a message to a fromSyntax rule', function () {
    seedDenialSections();
    bindDenialRules([
        WarrantRule::fromSyntax('they can update'),
        WarrantRule::fromSyntax('if is_teacher they cannot update')
            ->withDenialMessage('This section is locked.'),
    ]);

    expect(fn () => Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(WarrantTestSchema::class)->authorize('update', 'teacher:teacher-role'))
        ->toThrow(WarrantAuthorizationException::class, 'This section is locked.');
});

it('surfaces a because message written directly in the DSL', function () {
    seedDenialSections();
    bindDenialRules([
        WarrantRule::fromSyntax('they can update'),
        WarrantRule::fromSyntax("if is_teacher they cannot update because 'This section is locked.'"),
    ]);

    expect(fn () => Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(WarrantTestSchema::class)->authorize('update', 'teacher:teacher-role'))
        ->toThrow(WarrantAuthorizationException::class, 'This section is locked.');
});

it('surfaces per-clause because messages for distinct abilities in one if', function () {
    seedDenialSections();
    bindDenialRules(WarrantParser::parse(<<<'DSL'
        they can update, archive
        if is_teacher
        they cannot update because 'Cannot update a teacher row.'
        they cannot archive because 'Cannot archive a teacher row.'
        DSL));
    $user = makeWarrantTestUser('teacher-role');

    expect(fn () => Warrant::guard($user)->forSchema(WarrantTestSchema::class)->authorize('update', 'teacher:teacher-role'))
        ->toThrow(WarrantAuthorizationException::class, 'Cannot update a teacher row.');

    expect(fn () => Warrant::guard($user)->forSchema(WarrantTestSchema::class)->authorize('archive', 'teacher:teacher-role'))
        ->toThrow(WarrantAuthorizationException::class, 'Cannot archive a teacher row.');
});

it('surfaces per-clause messages from a fluent builder rule', function () {
    seedDenialSections();
    bindDenialRules([
        WarrantRule::build()->theyCan('update', 'archive')->toRule(),
        WarrantRule::build()->if('is_teacher')
            ->theyCannotBecause('update', 'Cannot update a teacher row.')
            ->theyCannotBecause('archive', 'Cannot archive a teacher row.')
            ->toRule(),
    ]);
    $user = makeWarrantTestUser('teacher-role');

    expect(fn () => Warrant::guard($user)->forSchema(WarrantTestSchema::class)->authorize('update', 'teacher:teacher-role'))
        ->toThrow(WarrantAuthorizationException::class, 'Cannot update a teacher row.');

    expect(fn () => Warrant::guard($user)->forSchema(WarrantTestSchema::class)->authorize('archive', 'teacher:teacher-role'))
        ->toThrow(WarrantAuthorizationException::class, 'Cannot archive a teacher row.');
});

it('scopes deniedAbilities to the fired clause message', function () {
    seedDenialSections();

    $captured = null;
    bindDenialRules([
        WarrantRule::build()->theyCan('update', 'archive')->toRule(),
        WarrantRule::build()->if('is_teacher')
            ->theyCannotBecause('update', function (WarrantDenialContext $c) use (&$captured) {
                $captured = $c;

                return 'no update';
            })
            ->theyCannotBecause('archive', 'no archive')
            ->toRule(),
    ]);

    try {
        Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(WarrantTestSchema::class)->authorize(['update', 'archive'], 'teacher:teacher-role');
    } catch (WarrantAuthorizationException) {
        // expected
    }

    // The update clause's closure sees only 'update' — not 'archive', which
    // carries a different message.
    expect($captured->deniedAbilities)->toBe(['update']);
});

it('surfaces a because message supplied through a binding closure', function () {
    seedDenialSections();
    bindDenialRules([
        WarrantRule::fromSyntax('they can update'),
        WarrantRule::fromSyntax('if is_teacher they cannot update because :msg', bindings: [
            'msg' => fn (WarrantDenialContext $c) => "No editing {$c->target->getKey()}.",
        ]),
    ]);

    expect(fn () => Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(WarrantTestSchema::class)->authorize('update', 'teacher:teacher-role'))
        ->toThrow(WarrantAuthorizationException::class, 'No editing teacher:teacher-role.');
});

it('leaves the original rule untouched (immutable wither)', function () {
    $original = WarrantRule::fromSyntax('if is_teacher they cannot update');
    $withMessage = $original->withDenialMessage('locked');

    expect($original->messageFor('update'))->toBeNull();
    expect($withMessage->messageFor('update'))->toBe('locked');
    expect($withMessage)->not->toBe($original);
});

it('accepts a closure message on a fromSyntax rule', function () {
    seedDenialSections();
    bindDenialRules([
        WarrantRule::fromSyntax('they can update'),
        WarrantRule::fromSyntax('if is_teacher they cannot update')
            ->withDenialMessage(fn (WarrantDenialContext $c) => "No editing {$c->target->getKey()}."),
    ]);

    expect(fn () => Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(WarrantTestSchema::class)->authorize('update', 'teacher:teacher-role'))
        ->toThrow(WarrantAuthorizationException::class, 'No editing teacher:teacher-role.');
});

// -- ungranted (no rule grants access) ----------------------------------------

it('surfaces the schema ungranted message when no rule grants access', function () {
    seedDenialSections();
    bindDenialRules([WarrantRule::build()->theyCan('view')->toRule()], DenialUngrantedSchema::class);

    // Nothing grants update, nothing forbids it -> ungranted hook fires.
    expect(fn () => Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(DenialUngrantedSchema::class)->authorize('update', 'teacher:teacher-role'))
        ->toThrow(WarrantAuthorizationException::class, 'Not permitted: update (ALL)');
});

it('throws a Throwable returned from the ungranted hook', function () {
    seedDenialSections();
    bindDenialRules([WarrantRule::build()->theyCan('view')->toRule()], DenialUngrantedThrowSchema::class);

    expect(fn () => Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(DenialUngrantedThrowSchema::class)->authorize('update', 'teacher:teacher-role'))
        ->toThrow(DenialCustomException::class, 'no grant for update');
});

it('gives the ungranted hook the whole gate under ANY', function () {
    seedDenialSections();
    bindDenialRules([WarrantRule::build()->theyCan('view')->toRule()], DenialUngrantedSchema::class);

    // ANY [update, archive]: both ungranted -> the whole gate is the subset.
    expect(fn () => Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(DenialUngrantedSchema::class)->authorizeAny(['update', 'archive'], 'teacher:teacher-role'))
        ->toThrow(WarrantAuthorizationException::class, 'Not permitted: update,archive (ANY)');
});

it('gives the ungranted hook only the missing abilities under ALL', function () {
    seedDenialSections();
    bindDenialRules([WarrantRule::build()->theyCan('view')->toRule()], DenialUngrantedSchema::class);

    // ALL [view, update]: view granted, update ungranted -> subset is just update.
    expect(fn () => Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(DenialUngrantedSchema::class)->authorize(['view', 'update'], 'teacher:teacher-role'))
        ->toThrow(WarrantAuthorizationException::class, 'Not permitted: update (ALL)');
});

it('does not treat a message-less cannot as ungranted', function () {
    seedDenialSections();
    bindDenialRules([
        WarrantRule::build()->theyCan('update')->toRule(),
        WarrantRule::build()->theyCannot('update')->toRule(),   // forbids, no message
    ], DenialUngrantedSchema::class);

    // Forbidden by a message-less cannot -> generic 403, NOT the ungranted message.
    expect(fn () => Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(DenialUngrantedSchema::class)->authorize('update', 'teacher:teacher-role'))
        ->toThrow(WarrantAuthorizationException::class, 'This action is unauthorized.');
});

it('prefers a message-bearing cannot over the ungranted hook', function () {
    seedDenialSections();
    bindDenialRules([
        WarrantRule::build()->theyCan('view')->toRule(),
        WarrantRule::build()->theyCannotBecause('view', 'view forbidden')->toRule(),
    ], DenialUngrantedSchema::class);

    // ALL [view, update]: view forbidden (with message), update ungranted -> forbid wins.
    expect(fn () => Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(DenialUngrantedSchema::class)->authorize(['view', 'update'], 'teacher:teacher-role'))
        ->toThrow(WarrantAuthorizationException::class, 'view forbidden');
});

it('resolves a wildcard cannot to the concrete gate abilities in deniedAbilities', function () {
    seedDenialSections();

    $captured = null;
    bindDenialRules([
        WarrantRule::build()->theyCan('update', 'view')->toRule(),
        WarrantRule::build()->if('is_teacher')
            ->theyCannotBecause('*', function (WarrantDenialContext $c) use (&$captured) {
                $captured = $c;

                return 'blocked';
            })->toRule(),
    ]);

    try {
        Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(WarrantTestSchema::class)->authorize(['update', 'view'], 'teacher:teacher-role');
    } catch (WarrantAuthorizationException) {
        // expected
    }

    expect($captured->gate->abilities)->toBe(['update', 'view']);
    expect($captured->deniedAbilities)->toBe(['update', 'view']); // '*' resolved against the gate
});

// -- forbidden (schema fallback for message-less cannots) ---------------------

it('catches a message-less cannot with the schema forbidden hook', function () {
    seedDenialSections();
    bindDenialRules([
        WarrantRule::build()->theyCan('update')->toRule(),
        WarrantRule::build()->theyCannot('update')->toRule(),   // forbids, no message
    ], DenialForbiddenSchema::class);

    expect(fn () => Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(DenialForbiddenSchema::class)->authorize('update', 'teacher:teacher-role'))
        ->toThrow(WarrantAuthorizationException::class, 'Forbidden: update');
});

it('prefers a rule message over the schema forbidden hook', function () {
    seedDenialSections();
    bindDenialRules([
        WarrantRule::build()->theyCan('update')->toRule(),
        WarrantRule::build()->theyCannotBecause('update', 'rule says no')->toRule(),
    ], DenialForbiddenSchema::class);

    expect(fn () => Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(DenialForbiddenSchema::class)->authorize('update', 'teacher:teacher-role'))
        ->toThrow(WarrantAuthorizationException::class, 'rule says no');
});

it('gives the forbidden hook the concrete blocked abilities of a wildcard cannot', function () {
    seedDenialSections();
    bindDenialRules([
        WarrantRule::build()->theyCan('update', 'view')->toRule(),
        WarrantRule::build()->if('is_teacher')->theyCannot('*')->toRule(),   // wildcard forbid, no message
    ], DenialForbiddenSchema::class);

    expect(fn () => Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(DenialForbiddenSchema::class)->authorize(['update', 'view'], 'teacher:teacher-role'))
        ->toThrow(WarrantAuthorizationException::class, 'Forbidden: update,view');
});

it('throws a Throwable returned from the forbidden hook', function () {
    seedDenialSections();
    bindDenialRules([
        WarrantRule::build()->theyCan('update')->toRule(),
        WarrantRule::build()->theyCannot('update')->toRule(),
    ], DenialForbiddenThrowSchema::class);

    expect(fn () => Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(DenialForbiddenThrowSchema::class)->authorize('update', 'teacher:teacher-role'))
        ->toThrow(DenialCustomException::class, 'forbidden update');
});

it('prefers the forbidden hook over the ungranted hook on a mixed denial', function () {
    seedDenialSections();
    bindDenialRules([
        WarrantRule::build()->theyCan('view')->toRule(),
        WarrantRule::build()->theyCannot('view')->toRule(),   // view forbidden (no message)
    ], DenialBothSchema::class);

    // ALL [view, update]: view forbidden, update ungranted -> forbid wins.
    expect(fn () => Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(DenialBothSchema::class)->authorize(['view', 'update'], 'teacher:teacher-role'))
        ->toThrow(WarrantAuthorizationException::class, 'forbidden:view');
});

it('falls through from a declining forbidden hook to the ungranted hook', function () {
    seedDenialSections();
    // DenialUngrantedSchema overrides only the ungranted hook; its forbidden hook
    // declines (null), so a mixed denial falls through to the ungranted message.
    bindDenialRules([
        WarrantRule::build()->theyCan('view')->toRule(),
        WarrantRule::build()->theyCannot('view')->toRule(),   // view forbidden (no message)
    ], DenialUngrantedSchema::class);

    // ALL [view, update]: view forbidden but the forbidden hook declines; update
    // is ungranted, so the ungranted hook answers.
    expect(fn () => Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(DenialUngrantedSchema::class)->authorize(['view', 'update'], 'teacher:teacher-role'))
        ->toThrow(WarrantAuthorizationException::class, 'Not permitted: update (ALL)');
});

// -- withDenialMessage wither guards ------------------------------------------

it('rejects withDenialMessage on a grant-only rule (fails fast)', function () {
    // The wither has nowhere to attach the message and throws immediately.
    expect(fn () => WarrantRule::fromSyntax('they can view')->withDenialMessage('pointless'))
        ->toThrow(InvalidArgumentException::class, 'requires a `they cannot ...` clause');
});

it('rejects withDenialMessage targeting an ability the rule does not deny', function () {
    expect(fn () => WarrantRule::fromSyntax('they cannot update')->withDenialMessage('nope', ['view']))
        ->toThrow(InvalidArgumentException::class, 'the rule does not deny it');
});

it('rejects an ability duplicated across a rule\'s cannot clauses', function () {
    seedDenialSections();
    bindDenialRules([
        WarrantRule::fromSyntax("if is_teacher they cannot update because 'a' they cannot update because 'b'"),
    ]);

    expect(fn () => Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(WarrantTestSchema::class)->authorize('update', 'teacher:teacher-role'))
        ->toThrow(InvalidArgumentException::class, 'appears in more than one');
});

// -- middleware integration ---------------------------------------------------

it('surfaces a rule message through the middleware', function () {
    useWarrantSchemas([WarrantScopedModelSchema::class]);
    Schema::create('course_sections', fn ($table) => $table->string('id'));
    DB::table('course_sections')->insert([['id' => 'teacher:teacher-role']]);

    bindWarrantRuleSet(WarrantRuleSet::fromRules(
        WarrantScopedModelSchema::class,
        [
            WarrantRule::build()->theyCan('view')->toRule(),
            WarrantRule::build()->if('is_teacher')->theyCannotBecause('view', 'teacher blocked')->toRule(),
        ],
    ));

    Route::bind('course_section', fn (string $value) => WarrantScopedModel::query()->find($value));
    Route::middleware([SubstituteBindings::class, 'warrant:course_section,all,view'])
        ->get('/__warrant/denial/{course_section}', fn () => response('ok'));

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs(makeWarrantTestUser('teacher-role'))->get('/__warrant/denial/teacher:teacher-role'))
        ->toThrow(WarrantAuthorizationException::class, 'teacher blocked');
});
