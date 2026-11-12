<?php

use Warrant\LanguageServer\Documents\ProjectFiles;
use Warrant\LanguageServer\Workspace;

/**
 * A new, empty directory under the system's temporary directory, removed once
 * the test ends.
 */
function scratchProject(): string
{
    $root = sys_get_temp_dir().'/'.uniqid('warrant_project_', true);
    mkdir($root);

    $GLOBALS['warrantScratchProjects'][] = $root;

    return $root;
}

/**
 * Write $contents to $relativePath under $root, making the directories it is in.
 */
function writeUnder(string $root, string $relativePath, string $contents): string
{
    $path = $root.'/'.$relativePath;

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), recursive: true);
    }

    file_put_contents($path, $contents);

    return $path;
}

/**
 * The paths of ProjectFiles::under($root), relative to $root.
 *
 * @return list<string>
 */
function projectFilesOf(string $root): array
{
    return array_map(static fn (string $path): string => substr($path, strlen($root) + 1), ProjectFiles::under($root));
}

function removeDirectory(string $directory): void
{
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    ) as $file) {
        $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }

    rmdir($directory);
}

afterEach(function () {
    foreach ($GLOBALS['warrantScratchProjects'] ?? [] as $directory) {
        if (is_dir($directory)) {
            removeDirectory($directory);
        }
    }

    $GLOBALS['warrantScratchProjects'] = [];
});

it('leaves out a git worktree inside the project, wherever it is and whatever it is called', function () {
    $root = scratchProject();
    writeUnder($root, 'app/Rules.php', '<?php');
    writeUnder($root, '.git/worktrees/feature/commondir', "../..\n");
    writeUnder($root, '.claude/worktrees/feature/.git', "gitdir: {$root}/.git/worktrees/feature\n");
    writeUnder($root, '.claude/worktrees/feature/app/Rules.php', '<?php');
    writeUnder($root, '.git/worktrees/other/commondir', "../..\n");
    writeUnder($root, 'checkouts/other/.git', "gitdir: {$root}/.git/worktrees/other\n");
    writeUnder($root, 'checkouts/other/app/Rules.php', '<?php');

    expect(projectFilesOf($root))->toBe(['app/Rules.php']);
});

it('reads a worktree\'s relative gitdir from the directory the .git file is in', function () {
    $root = scratchProject();
    writeUnder($root, 'app/Rules.php', '<?php');
    writeUnder($root, '.git/worktrees/feature/commondir', "../..\n");
    writeUnder($root, 'wt/feature/.git', "gitdir: ../../.git/worktrees/feature\r\n");
    writeUnder($root, 'wt/feature/app/Rules.php', '<?php');

    expect(projectFilesOf($root))->toBe(['app/Rules.php']);
});

it('searches a submodule, whose .git file names a repository with no commondir', function () {
    $root = scratchProject();
    writeUnder($root, '.git/modules/library/HEAD', "ref: refs/heads/main\n");
    writeUnder($root, 'library/.git', "gitdir: ../.git/modules/library\n");
    writeUnder($root, 'library/src/Rules.php', '<?php');

    expect(projectFilesOf($root))->toBe(['library/src/Rules.php']);
});

it('searches a submodule kept inside a worktree\'s state, which lies under a worktrees directory', function () {
    $root = scratchProject();
    writeUnder($root, '.git/worktrees/feature/modules/library/HEAD', "ref: refs/heads/main\n");
    writeUnder($root, 'library/.git', "gitdir: {$root}/.git/worktrees/feature/modules/library\n");
    writeUnder($root, 'library/src/Rules.php', '<?php');

    expect(projectFilesOf($root))->toBe(['library/src/Rules.php']);
});

it('searches a clone inside the project, whose .git is a directory, but not its .git directory', function () {
    $root = scratchProject();
    writeUnder($root, 'packages/library/.git/hooks/hook.php', '<?php');
    writeUnder($root, 'packages/library/src/Rules.php', '<?php');

    expect(projectFilesOf($root))->toBe(['packages/library/src/Rules.php']);
});

it('leaves out a worktree whose repository is gone, by the worktrees directory its gitdir names', function () {
    $root = scratchProject();
    writeUnder($root, 'moved/.git', "gitdir: /nowhere/repository/.git/worktrees/feature\n");
    writeUnder($root, 'moved/app/Rules.php', '<?php');
    writeUnder($root, 'stray/.git', "gitdir: /nowhere/repository/.git/modules/library\n");
    writeUnder($root, 'stray/app/Rules.php', '<?php');

    expect(projectFilesOf($root))->toBe(['stray/app/Rules.php']);
});

it('searches a directory whose .git file it cannot read as a gitdir', function () {
    $root = scratchProject();
    writeUnder($root, 'odd/.git', "not a gitdir line\n");
    writeUnder($root, 'odd/app/Rules.php', '<?php');
    writeUnder($root, 'empty/.git', '');
    writeUnder($root, 'empty/app/Rules.php', '<?php');

    expect(projectFilesOf($root))->toBe(['empty/app/Rules.php', 'odd/app/Rules.php']);
});

it('searches the project itself when it is a worktree, and leaves out a worktree inside it', function () {
    $repository = scratchProject();
    writeUnder($repository, '.git/worktrees/outer/commondir', "../..\n");
    writeUnder($repository, '.git/worktrees/inner/commondir', "../..\n");
    $root = $repository.'/outer';
    writeUnder($root, '.git', "gitdir: {$repository}/.git/worktrees/outer\n");
    writeUnder($root, 'app/Rules.php', '<?php');
    writeUnder($root, 'nested/inner/.git', "gitdir: {$repository}/.git/worktrees/inner\n");
    writeUnder($root, 'nested/inner/app/Rules.php', '<?php');

    expect(projectFilesOf($root))->toBe(['app/Rules.php']);
});

it('keeps no text of a file that cannot hold rule text, and reads it again once it changes', function () {
    $root = scratchProject();
    $path = writeUnder($root, 'app/Plain.php', '<?php return "is_owner";');
    $workspace = new Workspace($root);
    $read = fn (): array => (fn (): array => $this->read)->call($workspace);

    expect($workspace->projectDocumentsHolding('is_owner'))->toBe([])
        ->and($read()[$path][1])->toBeNull();

    file_put_contents($path, '<?php function rules() { return "if is_owner they can edit"; }');
    touch($path, time() + 10);

    expect(array_map(static fn ($document): string => $document->path, $workspace->projectDocumentsHolding('is_owner')))->toBe([$path])
        ->and($read()[$path][1]?->text)->toContain('function rules');
});

it('forgets the files it read that a new listing of the project no longer has', function () {
    $root = scratchProject();
    $kept = writeUnder($root, 'rules/kept.warrant', 'if is_owner they can edit');
    $deleted = writeUnder($root, 'rules/deleted.warrant', 'if is_owner they can view');
    $workspace = new Workspace($root);
    $read = fn (): array => (fn (): array => $this->read)->call($workspace);

    $workspace->projectDocumentsHolding('is_owner');
    expect(array_keys($read()))->toBe([$deleted, $kept]);

    unlink($deleted);
    $workspace->forgetProjectFiles();
    $workspace->projectDocumentsHolding('is_owner');

    expect(array_keys($read()))->toBe([$kept]);
});
