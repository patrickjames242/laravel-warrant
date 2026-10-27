<?php

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/warrant-artisan-format-'.bin2hex(random_bytes(4));
    mkdir($this->directory.'/app', recursive: true);
    file_put_contents($this->directory.'/rules.warrant', "for documents{ if is_mine they can   view }\n");
    file_put_contents($this->directory.'/app/DocumentSchema.php', "<?php\n\nwarrant('if is_self   they can edit,view');\n");
});

afterEach(function () {
    (new Illuminate\Filesystem\Filesystem)->deleteDirectory($this->directory);
});

it('formats the files and glob patterns it is given', function () {
    $this->artisan('warrant:format', ['paths' => [$this->directory.'/rules.warrant', $this->directory.'/app/*.php']])
        ->expectsOutputToContain('Formatted')
        ->assertExitCode(0);

    expect(file_get_contents($this->directory.'/rules.warrant'))->toBe("for documents { if is_mine they can view }\n")
        ->and(file_get_contents($this->directory.'/app/DocumentSchema.php'))->toBe("<?php\n\nwarrant('if is_self they can edit, view');\n");
});

it('writes nothing under --check, and exits with 1 when a file would change', function () {
    $this->artisan('warrant:format', ['paths' => [$this->directory], '--check' => true])
        ->expectsOutputToContain('Would format')
        ->assertExitCode(1);

    expect(file_get_contents($this->directory.'/rules.warrant'))->toBe("for documents{ if is_mine they can   view }\n");
});

it('fails on rule text that does not parse, unless told to ignore it', function () {
    file_put_contents($this->directory.'/broken.warrant', "for x { they can v, }\n");

    $this->artisan('warrant:format', ['paths' => [$this->directory.'/broken.warrant']])
        ->expectsOutputToContain('Line 1, column 21')
        ->assertExitCode(2);

    $this->artisan('warrant:format', ['paths' => [$this->directory.'/broken.warrant'], '--ignore-unparsable' => true])
        ->assertExitCode(0);
});

it('fails for a path that matches nothing', function () {
    $this->artisan('warrant:format', ['paths' => [$this->directory.'/nothing/*.warrant']])
        ->assertExitCode(2);
});
