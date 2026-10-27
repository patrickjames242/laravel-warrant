<?php

/**
 * Run bin/warrant-format with $arguments, and return its exit code and output.
 *
 * @param list<string> $arguments
 * @return array{0: int, 1: string}
 */
function runFormatCommand(array $arguments): array
{
    $command = array_merge([PHP_BINARY, dirname(__DIR__, 2).'/bin/warrant-format'], $arguments);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);

    return [proc_close($process), $output];
}

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/warrant-format-'.bin2hex(random_bytes(4));
    mkdir($this->directory.'/vendor', recursive: true);
    file_put_contents($this->directory.'/rules.warrant', "for documents{ if is_mine they can   view }\n");
    file_put_contents($this->directory.'/vendor/package.warrant', "for documents{ if is_mine they can   view }\n");
});

afterEach(function () {
    foreach (['rules.warrant', 'broken.warrant', 'vendor/package.warrant'] as $file) {
        @unlink($this->directory.'/'.$file);
    }

    rmdir($this->directory.'/vendor');
    rmdir($this->directory);
});

it('lists the files that would change under --check, and writes nothing', function () {
    [$exitCode, $output] = runFormatCommand(['--check', $this->directory]);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('rules.warrant')
        ->and($output)->not->toContain('package.warrant')
        ->and(file_get_contents($this->directory.'/rules.warrant'))->toBe("for documents{ if is_mine they can   view }\n");
});

it('formats files in place, and then finds nothing to change', function () {
    [$exitCode] = runFormatCommand([$this->directory]);

    expect($exitCode)->toBe(0)
        ->and(file_get_contents($this->directory.'/rules.warrant'))->toBe("for documents { if is_mine they can view }\n")
        ->and(file_get_contents($this->directory.'/vendor/package.warrant'))->toBe("for documents{ if is_mine they can   view }\n")
        ->and(runFormatCommand(['--check', $this->directory])[0])->toBe(0);
});

it('reports rule text that does not parse, leaves it as it is, and fails', function () {
    file_put_contents($this->directory.'/broken.warrant', "for x { they can v, }\n");

    [$exitCode, $output] = runFormatCommand([$this->directory.'/broken.warrant']);

    expect($exitCode)->toBe(2)
        ->and($output)->toContain('broken.warrant:1: Line 1, column 21')
        ->and(file_get_contents($this->directory.'/broken.warrant'))->toBe("for x { they can v, }\n");
});

it('formats standard input as the file at --stdin-filepath would be', function () {
    $command = [PHP_BINARY, dirname(__DIR__, 2).'/bin/warrant-format', '--stdin-filepath=rules.warrant'];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], 'for documents{ if is_mine they can   view }');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);

    expect(proc_close($process))->toBe(0)
        ->and($output)->toBe("for documents { if is_mine they can view }\n");
});

it('still reports rule text that does not parse under --ignore-unparsable, but does not fail', function () {
    file_put_contents($this->directory.'/broken.warrant', "for x { they can v, }\n");

    [$exitCode, $output] = runFormatCommand(['--ignore-unparsable', $this->directory.'/broken.warrant']);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('broken.warrant:1: Line 1, column 21');
});

it('shows what would change under --diff, and writes nothing', function () {
    [$exitCode, $output] = runFormatCommand(['--diff', $this->directory.'/rules.warrant']);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain("-for documents{ if is_mine they can   view }\n+for documents { if is_mine they can view }")
        ->and(file_get_contents($this->directory.'/rules.warrant'))->toBe("for documents{ if is_mine they can   view }\n");
});

it('formats the files a quoted glob pattern matches, and fails for a pattern that matches nothing', function () {
    [$exitCode] = runFormatCommand([$this->directory.'/**/*.warrant']);
    [$unmatched, $output] = runFormatCommand([$this->directory.'/**/*.nothing']);

    expect($exitCode)->toBe(0)
        ->and(file_get_contents($this->directory.'/rules.warrant'))->toBe("for documents { if is_mine they can view }\n")
        ->and(file_get_contents($this->directory.'/vendor/package.warrant'))->toBe("for documents{ if is_mine they can   view }\n")
        ->and($unmatched)->toBe(2)
        ->and($output)->toContain('No file, directory or pattern matches');
});
