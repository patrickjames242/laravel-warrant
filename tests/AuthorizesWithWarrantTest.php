<?php

require_once __DIR__.'/Support/TestSupport.php';

use Illuminate\Contracts\Auth\Authenticatable;
use Warrant\AuthorizesWithWarrant;
use Warrant\Schema\WarrantSchema;
use Warrant\WarrantGuard;
use Warrant\WarrantGuardForSchema;

class WarrantTraitUser extends WarrantTestUser
{
    use AuthorizesWithWarrant;
}

beforeEach(function () {
    useWarrantSchemas(['course_sections' => WarrantTestSchema::class]);
});

it('returns a WarrantGuard from ->warrant()', function () {
    $user = new WarrantTraitUser('teacher-role');

    expect($user->warrant())->toBeInstanceOf(WarrantGuard::class);
});

it('binds the guard to the calling user', function () {
    $user = new WarrantTraitUser('teacher-role');

    $forSchema = $user->warrant()->forSchema(WarrantTestSchema::class);

    expect($forSchema)->toBeInstanceOf(WarrantGuardForSchema::class)
        ->and($forSchema->user())->toBe($user);
});
