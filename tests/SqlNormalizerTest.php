<?php

require_once __DIR__.'/Support/TestSupport.php';

// -- whitespace / newlines ----------------------------------------------------

it('collapses insignificant whitespace and newlines', function () {
    $a = "select   *\n  from   \"docs\"\twhere \"id\"  =  ?";
    $b = 'select * from "docs" where "id" = ?';

    expect(normalizeWarrantSql($a))->toBe(normalizeWarrantSql($b));
    expect(normalizeWarrantSql($a))->toBe('select * from "docs" where "id" = ?');
});

it('converges different comma and operator spacing', function () {
    expect(normalizeWarrantSql('select a,b ,c , d'))
        ->toBe(normalizeWarrantSql('select a, b, c, d'))
        ->toBe('select a, b, c, d');

    expect(normalizeWarrantSql('"t"."id"=?'))
        ->toBe(normalizeWarrantSql('"t" . "id" = ?'))
        ->toBe('"t"."id" = ?');
});

// -- case ---------------------------------------------------------------------

it('lower-cases keywords and unquoted identifiers but not quoted forms', function () {
    expect(normalizeWarrantSql('SELECT * FROM "Docs" WHERE Role_Id = ?'))
        ->toBe('select * from "Docs" where role_id = ?');
});

it('never alters the contents of a string literal', function () {
    // inner spaces, comma, parens and casing inside the literal are preserved
    $sql = "select * from \"docs\" where name = 'A ,  ( B )  c'";

    expect(normalizeWarrantSql($sql))->toBe($sql);
});

// -- redundant parentheses ----------------------------------------------------

it('removes redundant doubled parentheses', function () {
    expect(normalizeWarrantSql('select * from "docs" where (("docs"."id" = ?))'))
        ->toBe('select * from "docs" where ("docs"."id" = ?)');

    // nested redundancy collapses fully to a single pair
    expect(normalizeWarrantSql('where ((("id" = ?)))'))
        ->toBe('where ("id" = ?)');
});

it('keeps parentheses that are not pure doubled wrappers', function () {
    // sibling groups and grouping that carries meaning are untouched
    expect(normalizeWarrantSql('where (a = ?) and (b = ?)'))
        ->toBe('where (a = ?) and (b = ?)');

    expect(normalizeWarrantSql('where (a or b) and c'))
        ->toBe('where (a or b) and c');
});

// -- comments & terminators ---------------------------------------------------

it('strips comments and trailing semicolons', function () {
    expect(normalizeWarrantSql("select * from \"docs\" -- a comment\nwhere \"id\" = ?;"))
        ->toBe('select * from "docs" where "id" = ?');

    expect(normalizeWarrantSql('select 1 /* block */ from "docs" ; ;'))
        ->toBe('select 1 from "docs"');
});

// -- validity -----------------------------------------------------------------

it('produces a string SQLite still accepts', function () {
    Illuminate\Support\Facades\Schema::create('docs', fn ($t) => $t->string('id'));
    Illuminate\Support\Facades\DB::table('docs')->insert([['id' => 'a'], ['id' => 'b']]);

    $normalized = normalizeWarrantSql(
        "SELECT   *\nFROM \"docs\"\nWHERE ((\"docs\".\"id\" = 'a'));"
    );

    expect($normalized)->toBe('select * from "docs" where ("docs"."id" = \'a\')');
    expect(Illuminate\Support\Facades\DB::select($normalized))->toHaveCount(1);
});
