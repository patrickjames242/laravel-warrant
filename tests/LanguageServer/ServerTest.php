<?php

require_once __DIR__.'/Support.php';

use Warrant\LanguageServer\Documents\Uri;
use Warrant\LanguageServer\Protocol\Server;
use Warrant\LanguageServer\Protocol\StdioTransport;

/**
 * Run the server over $messages, as an editor that sends them all and then
 * closes the connection, and return what it sent back and its exit code.
 *
 * @param list<array<string, mixed>> $messages
 * @return array{0: list<array<string, mixed>>, 1: int}
 */
function serve(array $messages): array
{
    $input = fopen('php://temp', 'r+');
    $output = fopen('php://temp', 'r+');

    foreach ($messages as $message) {
        $body = json_encode(['jsonrpc' => '2.0', ...$message]);
        fwrite($input, 'Content-Length: '.strlen($body)."\r\n\r\n".$body);
    }

    rewind($input);

    $exitCode = (new Server(new StdioTransport($input, $output), dirname(__DIR__, 2).'/bin/warrant-language-server', canWait: false, log: fopen('php://memory', 'w')))->run();

    rewind($output);
    $reader = new StdioTransport($output, fopen('php://memory', 'w'));
    $reader->fill();
    $sent = [];

    while (($message = $reader->next()) !== null) {
        $sent[] = $message;
    }

    return [$sent, $exitCode];
}

/**
 * The messages an editor opens with: the handshake over the fixture project,
 * reading schemas from source only.
 *
 * @return list<array<string, mixed>>
 */
function handshake(): array
{
    return [
        ['id' => 1, 'method' => 'initialize', 'params' => [
            'rootUri' => Uri::fromPath(fixtureRoot()),
            'capabilities' => [],
            'initializationOptions' => ['vocabulary' => 'static'],
        ]],
        ['method' => 'initialized', 'params' => []],
    ];
}

/**
 * @param list<array<string, mixed>> $sent
 * @return array<string, mixed>|null
 */
function responseTo(array $sent, int $id): ?array
{
    foreach ($sent as $message) {
        if (($message['id'] ?? null) === $id && ! isset($message['method'])) {
            return $message;
        }
    }

    return null;
}

it('answers the handshake with what it can do', function () {
    [$sent] = serve(handshake());

    expect(responseTo($sent, 1)['result']['capabilities'])->toMatchArray([
        'positionEncoding' => 'utf-16',
        'textDocumentSync' => ['openClose' => true, 'change' => 1],
        'hoverProvider' => true,
        'definitionProvider' => true,
        'documentSymbolProvider' => true,
    ]);
});

it('sends diagnostics for the latest text of an open document once the editor is quiet', function () {
    $uri = Uri::fromPath(fixtureRoot().'/rules.warrant');

    [$sent] = serve([
        ...handshake(),
        ['method' => 'textDocument/didOpen', 'params' => ['textDocument' => ['uri' => $uri, 'languageId' => 'warrant', 'version' => 1, 'text' => 'for documents { they can edti }']]],
        ['method' => 'textDocument/didChange', 'params' => ['textDocument' => ['uri' => $uri, 'version' => 2], 'contentChanges' => [['text' => 'for documents { they can edit }']]]],
    ]);

    $published = array_values(array_filter($sent, static fn (array $message): bool => ($message['method'] ?? null) === 'textDocument/publishDiagnostics'));

    expect($published)->toHaveCount(1)
        ->and($published[0]['params']['version'])->toBe(2)
        ->and($published[0]['params']['diagnostics'])->toBe([]);
});

it('answers requests about an open document', function () {
    $uri = Uri::fromPath(fixtureRoot().'/rules.warrant');

    [$sent] = serve([
        ...handshake(),
        ['method' => 'textDocument/didOpen', 'params' => ['textDocument' => ['uri' => $uri, 'languageId' => 'warrant', 'version' => 1, 'text' => 'for documents { they can edit }']]],
        ['id' => 2, 'method' => 'textDocument/hover', 'params' => ['textDocument' => ['uri' => $uri], 'position' => ['line' => 0, 'character' => 26]]],
        ['id' => 3, 'method' => 'textDocument/completion', 'params' => ['textDocument' => ['uri' => $uri], 'position' => ['line' => 0, 'character' => 25]]],
        ['id' => 4, 'method' => 'textDocument/hover', 'params' => ['textDocument' => ['uri' => 'file:///not/open.warrant'], 'position' => ['line' => 0, 'character' => 0]]],
    ]);

    expect(responseTo($sent, 2)['result']['contents']['value'])->toStartWith('**ability `edit` on `documents`**')
        ->and(array_column(responseTo($sent, 3)['result']['items'], 'label'))->toContain('view', 'edit')
        ->and(responseTo($sent, 4)['result'])->toBeNull();
});

it('answers a request it does not know with an error, and ignores a notification it does not know', function () {
    [$sent] = serve([
        ...handshake(),
        ['method' => '$/setTrace', 'params' => ['value' => 'off']],
        ['id' => 2, 'method' => 'textDocument/rename', 'params' => []],
    ]);

    expect(responseTo($sent, 2)['error']['code'])->toBe(-32601)
        ->and(count($sent))->toBe(2);
});

it('refuses requests before the handshake', function () {
    [$sent] = serve([['id' => 1, 'method' => 'textDocument/hover', 'params' => []]]);

    expect(responseTo($sent, 1)['error']['code'])->toBe(-32002);
});

it('exits with 0 after a shutdown and 1 without one', function () {
    [, $afterShutdown] = serve([...handshake(), ['id' => 2, 'method' => 'shutdown'], ['method' => 'exit']]);
    [, $withoutShutdown] = serve([...handshake(), ['method' => 'exit']]);

    expect($afterShutdown)->toBe(0)
        ->and($withoutShutdown)->toBe(1);
});

it('starts without loading the project\'s Composer autoloader, so the project\'s platform check never stops it', function () {
    // The package laid out as Composer installs it, in a project whose autoloader
    // throws as Composer's platform check does on a PHP the project does not accept.
    $project = sys_get_temp_dir().'/warrant-server-'.bin2hex(random_bytes(4));
    $package = $project.'/vendor/patrickhanna/laravel-warrant';
    mkdir($package.'/bin', recursive: true);
    copy(dirname(__DIR__, 2).'/bin/warrant-language-server', $package.'/bin/warrant-language-server');
    symlink(dirname(__DIR__, 2).'/src', $package.'/src');
    file_put_contents($project.'/vendor/autoload.php', "<?php\nthrow new RuntimeException('Composer detected issues in your platform.');\n");

    $input = '';

    foreach ([
        ['id' => 1, 'method' => 'initialize', 'params' => ['rootUri' => Uri::fromPath($project), 'capabilities' => [], 'initializationOptions' => ['vocabulary' => 'static']]],
        ['id' => 2, 'method' => 'shutdown'],
        ['method' => 'exit'],
    ] as $message) {
        $body = json_encode(['jsonrpc' => '2.0', ...$message]);
        $input .= 'Content-Length: '.strlen($body)."\r\n\r\n".$body;
    }

    $process = proc_open([PHP_BINARY, $package.'/bin/warrant-language-server'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $project);
    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    $exitCode = proc_close($process);

    unlink($package.'/src');
    unlink($package.'/bin/warrant-language-server');
    unlink($project.'/vendor/autoload.php');

    foreach ([$package.'/bin', $package, $project.'/vendor/patrickhanna', $project.'/vendor', $project] as $directory) {
        rmdir($directory);
    }

    expect($errors)->toBe('')
        ->and($output)->toContain('"serverInfo":{"name":"warrant-language-server"}')
        ->and($exitCode)->toBe(0);
});

it('formats a whole .warrant file, and the rule text in a PHP file only as a code action', function () {
    $warrant = Uri::fromPath(fixtureRoot().'/rules.warrant');
    $php = Uri::fromPath(fixtureRoot().'/app/Unsaved.php');
    $phpText = "<?php\n\nwarrant('if is_self   they can edit,view');\n";

    [$sent] = serve([
        ...handshake(),
        ['method' => 'textDocument/didOpen', 'params' => ['textDocument' => ['uri' => $warrant, 'languageId' => 'warrant', 'version' => 1, 'text' => "for documents{ they can   edit }\n"]]],
        ['method' => 'textDocument/didOpen', 'params' => ['textDocument' => ['uri' => $php, 'languageId' => 'php', 'version' => 1, 'text' => $phpText]]],
        ['id' => 2, 'method' => 'textDocument/formatting', 'params' => ['textDocument' => ['uri' => $warrant], 'options' => ['tabSize' => 4, 'insertSpaces' => true]]],
        ['id' => 3, 'method' => 'textDocument/codeAction', 'params' => ['textDocument' => ['uri' => $php], 'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 0]], 'context' => ['diagnostics' => [], 'only' => ['source']]]],
        ['id' => 4, 'method' => 'textDocument/codeAction', 'params' => ['textDocument' => ['uri' => $php], 'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 0]], 'context' => ['diagnostics' => []]]],
    ]);

    expect(responseTo($sent, 2)['result'])->toBe([[
        'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 1, 'character' => 0]],
        'newText' => "for documents { they can edit }\n",
    ]])
        ->and(responseTo($sent, 3)['result'][0]['kind'])->toBe('source.format.warrant')
        ->and(responseTo($sent, 3)['result'][0]['edit']['changes'][$php])->toBe([[
            'range' => ['start' => ['line' => 2, 'character' => 9], 'end' => ['line' => 2, 'character' => 40]],
            'newText' => 'if is_self they can edit, view',
        ]])
        ->and(responseTo($sent, 4)['result'])->toBe([]);
});

it('registers formatting for .warrant files only when the editor can take a registration', function () {
    [$sent] = serve([
        ['id' => 1, 'method' => 'initialize', 'params' => [
            'rootUri' => Uri::fromPath(fixtureRoot()),
            'capabilities' => ['textDocument' => ['formatting' => ['dynamicRegistration' => true]]],
            'initializationOptions' => ['vocabulary' => 'static'],
        ]],
        ['method' => 'initialized', 'params' => []],
    ]);

    $registration = array_values(array_filter($sent, static fn (array $message): bool => ($message['method'] ?? null) === 'client/registerCapability'));

    expect(responseTo($sent, 1)['result']['capabilities']['documentFormattingProvider'])->toBeFalse()
        ->and($registration[0]['params']['registrations'][0]['method'])->toBe('textDocument/formatting')
        ->and($registration[0]['params']['registrations'][0]['registerOptions']['documentSelector'])->toContain(['pattern' => '**/*.warrant']);
});

it('completes a condition into its parentheses for an editor that takes snippets', function () {
    $uri = Uri::fromPath(fixtureRoot().'/rules.warrant');
    $initialize = static fn (bool $snippets): array => ['id' => 1, 'method' => 'initialize', 'params' => [
        'rootUri' => Uri::fromPath(fixtureRoot()),
        'capabilities' => ['textDocument' => ['completion' => ['completionItem' => ['snippetSupport' => $snippets]]]],
        'initializationOptions' => ['vocabulary' => 'static'],
    ]];
    $insertOf = static function (array $sent): array {
        foreach (responseTo($sent, 2)['result']['items'] as $item) {
            if ($item['label'] === 'in_folder') {
                return [$item['textEdit']['newText'], $item['insertTextFormat'] ?? null];
            }
        }

        return [];
    };
    $messages = [
        ['method' => 'initialized', 'params' => []],
        ['method' => 'textDocument/didOpen', 'params' => ['textDocument' => ['uri' => $uri, 'languageId' => 'warrant', 'version' => 1, 'text' => 'for documents { if  }']]],
        ['id' => 2, 'method' => 'textDocument/completion', 'params' => ['textDocument' => ['uri' => $uri], 'position' => ['line' => 0, 'character' => 19]]],
    ];

    expect($insertOf(serve([$initialize(true), ...$messages])[0]))->toBe(['in_folder($0)', 2])
        ->and($insertOf(serve([$initialize(false), ...$messages])[0]))->toBe(['in_folder', null]);
});
