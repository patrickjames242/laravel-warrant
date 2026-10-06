<?php

use Warrant\DSL\Compiling\TruthValue;
use Warrant\Support\Set;

it('adds, removes and looks up members', function () {
    $set = new Set('a', 'b', 'a');

    expect($set->size())->toBe(2)
        ->and($set->has('a'))->toBeTrue()
        ->and($set->has('c'))->toBeFalse();

    $set->add('c');
    $set->remove('a');
    $set->remove('missing');

    expect($set->values())->toBe(['b', 'c'])
        ->and($set->size())->toBe(2);
});

it('combines truth values under Kleene logic', function () {
    [$t, $f, $u] = [TruthValue::TRUE, TruthValue::FALSE, TruthValue::UNKNOWN];

    expect($f->and($u))->toBe($f)
        ->and($t->and($u))->toBe($u)
        ->and($t->and($t))->toBe($t)
        ->and($t->or($u))->toBe($t)
        ->and($f->or($u))->toBe($u)
        ->and($f->or($f))->toBe($f)
        ->and($u->not())->toBe($u)
        ->and($t->not())->toBe($f);
});
