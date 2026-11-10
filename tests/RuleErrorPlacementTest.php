<?php

require_once __DIR__.'/Support/TestSupport.php';
require_once __DIR__.'/Fixtures/RuleErrors/elsewhere.php';

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
use Warrant\DSL\Parsing\BoundSyntax;
use Warrant\DSL\Parsing\Positions\RuleTextExcerpt;
use Warrant\DSL\Parsing\SyntaxSource;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Validation\RuleValidationException;
use Warrant\Facades\Warrant;
use Warrant\HasWarrantSchema;
use Warrant\Rules\RuleProviderContext;
use Warrant\Schema\Abilities\Ability;
use Warrant\Schema\Conditions\Global\GlobalCondition;
use Warrant\Schema\Conditions\Global\GlobalConditionContext;
use Warrant\Schema\Conditions\Row\RowCondition;
use Warrant\Schema\Conditions\Row\RowConditionContext;
use Warrant\Schema\Relations\RowRelation;
use Warrant\Schema\Relations\RowRelationContext;
use Warrant\Schema\Types\Classes\ModelSchema;
use Warrant\Schema\Types\Classes\RowlessSchema;

/*
|--------------------------------------------------------------------------
| Where an error in rule text points
|--------------------------------------------------------------------------
|
| An error shows the file and line the text is written in, when that file can
| be shown to hold the text: the .warrant file it was read from, the string a
| warrant() call was given wherever that call is, or the one string the method
| that answered returns. Otherwise it names what answered and shows the text's
| own lines. Either way the lines around the error are shown, the text it is
| about is underlined, and a hint names what was likely meant.
|
*/

beforeEach(function () {
    useWarrantSchemas([
        're_heredoc' => ReHeredocSchema::class,
        're_file' => ReFileSchema::class,
        're_called' => ReCalledSchema::class,
        're_plain' => RePlainSchema::class,
        're_docs' => ReDocSchema::class,
        're_folders' => ReFolderSchema::class,
    ]);
});

/** The error checking $schema's rules raises. */
function ruleErrorOf(string $schema): RuleValidationException
{
    try {
        Warrant::guard(makeWarrantTestUser())->forSchema($schema)->expandedRuleSet();
    } catch (RuleValidationException $error) {
        return $error;
    }

    throw new LogicException("{$schema}'s rules raised no error.");
}

// -- the place -----------------------------------------------------------------

it('points into the method that returns the text, leaving the place out of the message', function () {
    $error = ruleErrorOf(ReHeredocSchema::class);
    $line = lineWriting(__FILE__, "            and is_ownr\n");

    expect($error->excerpt->file?->path)->toBe(__FILE__)
        ->and($error->excerpt->line())->toBe($line)
        ->and($error->excerpt->column())->toBe(17)
        ->and($error->getFile())->toBe(__FILE__)
        ->and($error->getLine())->toBe($line)
        ->and($error->getMessage())->toBe('Condition [is_ownr] is declared by neither schema [re_heredoc] nor any global schema. Did you mean [is_owner]?');
});

it('points into the .warrant file the text was read from', function () {
    $error = ruleErrorOf(ReFileSchema::class);
    $path = realpath(__DIR__.'/Fixtures/RuleErrors/rules.warrant');

    expect($error->excerpt->file?->path)->toBe($path)
        ->and($error->getFile())->toBe($path)
        ->and($error->getLine())->toBe(2)
        ->and($error->excerpt->column())->toBe(9);
});

it('points into the warrant() call that wrote the text, wherever that call is', function () {
    $error = ruleErrorOf(ReCalledSchema::class);
    $path = realpath(__DIR__.'/Fixtures/RuleErrors/elsewhere.php');
    $line = lineWriting($path, "            and is_ownr\n        they can view\n        WARRANT);");

    expect($error->excerpt->file?->path)->toBe($path)
        ->and($error->getFile())->toBe($path)
        ->and($error->getLine())->toBe($line)
        ->and($error->excerpt->column())->toBe(17);
});

it('names the place and what answered with the text in the message, when no file can be shown to hold it', function () {
    $error = ruleErrorOf(RePlainSchema::class);

    expect($error->excerpt->file)->toBeNull()
        ->and($error->getFile())->toEndWith('SyntaxValidator.php')
        ->and($error->getMessage())->toBe('Condition [is_ownr] is declared by neither schema [re_plain] nor any global schema. (line 2, column 9 of the rules() of schema [RePlainSchema]) Did you mean [is_owner]?');
});

it('does not point into a file that holds no string with the text', function () {
    $text = 'if is_'.'ownr they can view';
    $source = new SyntaxSource(new BoundSyntax($text, calledInFile: __FILE__, calledOnLine: 1));

    expect(RuleTextExcerpt::of($text, 3, 10, $source)->file)->toBeNull();
});

it('places a syntax error in the message the same way', function () {
    try {
        WarrantSyntax::parse("if is_owner\nthey can view )");
    } catch (Throwable $error) {
    }

    expect($error->getMessage())->toBe('Unexpected token; expected end of input. (line 2, column 15)');
});

// -- the lines around it ----------------------------------------------------------

it('lays out the lines around an error in the file, underlining all of what it is about', function () {
    $error = ruleErrorOf(ReFileSchema::class);
    $path = realpath(__DIR__.'/Fixtures/RuleErrors/rules.warrant');

    expect($error->excerpt->describe($error->reason, $error->hint))->toBe(<<<TEXT
        Condition [is_ownr] is declared by neither schema [re_file] nor any global schema.

          --> {$path}:2:9
              in the rules() of schema [ReFileSchema]

          1 | if is_owner
          2 |     and is_ownr
            |         ^^^^^^^
          3 | they can view

        Did you mean [is_owner]?
        TEXT);
});

it('lays out the text\'s own lines when no file was found', function () {
    try {
        validateRegistered(WarrantSyntax::parse("for re_heredoc {\n  if is_owner\n    and is_ownr\n  they can view\n}"));
    } catch (RuleValidationException $error) {
    }

    expect($error->excerpt->describe($error->reason))->toBe(<<<'TEXT'
        Condition [is_ownr] is declared by neither schema [re_heredoc] nor any global schema.

          --> line 3, column 9

          2 |   if is_owner
          3 |     and is_ownr
            |         ^^^^^^^
          4 |   they can view
        TEXT);
});

// -- the hint -------------------------------------------------------------------

it('lists the row relations a schema declares, and the closest, for one it does not', function () {
    try {
        validateRegistered(WarrantSyntax::parse('for re_docs { if can(view for its foldr) they can view }'));
    } catch (RuleValidationException $error) {
    }

    expect($error->reason)->toBe('Schema [re_docs] declares no row relation [foldr].')
        ->and($error->hint)->toBe('Schema [re_docs] declares the row relations [folder] and [owner]. Did you mean [folder]? A row relation is a #[RowRelation] method on ReDocSchema.');
});

it('names the closest schema for one that is not registered', function () {
    try {
        validateRegistered(WarrantSyntax::parse('for re_docs { if can(view for re_foldrs(@column folder_id)) they can view }'));
    } catch (RuleValidationException $error) {
    }

    expect($error->hint)->toBe('Did you mean [re_folders]?');
});

it('offers no hint when nothing declared is close', function () {
    try {
        validateRegistered(WarrantSyntax::parse('for re_docs { if completely_different they can view }'));
    } catch (RuleValidationException $error) {
    }

    expect($error->hint)->toBeNull();
});

// -- fixtures -------------------------------------------------------------------

class ReHeredocSchema extends RowlessSchema
{
    #[Ability]
    public const VIEW = 'view';

    #[GlobalCondition]
    public function isOwner(GlobalConditionContext $c): bool
    {
        return true;
    }

    public function rules(RuleProviderContext $context): string|BoundSyntax
    {
        return <<<'WARRANT'
        if is_owner
            and is_ownr
        they can view
        WARRANT;
    }
}

class ReFileSchema extends ReHeredocSchema
{
    public function rules(RuleProviderContext $context): string|BoundSyntax
    {
        return warrant_file(__DIR__.'/Fixtures/RuleErrors/rules');
    }
}

class ReCalledSchema extends ReHeredocSchema
{
    public function rules(RuleProviderContext $context): string|BoundSyntax
    {
        return ruleErrorsThroughWarrant();
    }
}

class RePlainSchema extends ReHeredocSchema
{
    public function rules(RuleProviderContext $context): string|BoundSyntax
    {
        return ruleErrorsAsPlainString();
    }
}

class ReDoc extends Model
{
    use HasWarrantSchema;

    protected $table = 're_docs';

    public static function warrantSchema(): string
    {
        return ReDocSchema::class;
    }
}

class ReFolder extends Model
{
    use HasWarrantSchema;

    protected $table = 're_folders';

    public static function warrantSchema(): string
    {
        return ReFolderSchema::class;
    }
}

class ReFolderSchema extends ModelSchema
{
    public const model = ReFolder::class;

    #[Ability]
    public const VIEW = 'view';
}

class ReDocSchema extends ModelSchema
{
    public const model = ReDoc::class;

    #[Ability]
    public const VIEW = 'view';

    #[RowCondition]
    public function isOwner(RowConditionContext $c): BuilderContract
    {
        return $c->query->where($c->column('owner'), $c->user->role_id);
    }

    #[RowRelation(ReFolderSchema::class)]
    public function folder(RowRelationContext $c): BuilderContract
    {
        return $c->relatedQuery->where($c->relatedColumn('id'), $c->ownColumn('folder_id'));
    }

    #[RowRelation(ReDocSchema::class)]
    public function owner(RowRelationContext $c): BuilderContract
    {
        return $c->relatedQuery->where($c->relatedColumn('id'), $c->ownColumn('owner_id'));
    }
}
