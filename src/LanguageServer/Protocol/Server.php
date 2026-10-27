<?php

namespace Warrant\LanguageServer\Protocol;

use Throwable;
use Warrant\LanguageServer\Documents\Document;
use Warrant\LanguageServer\Documents\Uri;
use Warrant\LanguageServer\Features\CodeActions;
use Warrant\LanguageServer\Features\Completion;
use Warrant\LanguageServer\Features\Definition;
use Warrant\LanguageServer\Features\Diagnostics;
use Warrant\LanguageServer\Features\DocumentSymbols;
use Warrant\LanguageServer\Features\Formatting;
use Warrant\LanguageServer\Features\Hover;
use Warrant\LanguageServer\Vocabulary\ApplicationVocabularyProcess;
use Warrant\LanguageServer\Vocabulary\ClassFileLocator;
use Warrant\LanguageServer\Vocabulary\StaticSchemaScanner;
use Warrant\LanguageServer\Vocabulary\VocabularyIndex;
use Warrant\LanguageServer\VocabularyMode;
use Warrant\LanguageServer\Workspace;

/**
 * The Warrant language server: reads the editor's messages, keeps the open
 * documents and the project's schemas, and answers.
 *
 * Diagnostics are sent once the editor has gone quiet for a moment, so a burst
 * of typing is analysed once rather than at every keystroke. The schemas are read
 * from source straight away, and in `auto` mode also from the running
 * application in a child process, whose answer replaces the reading from source
 * whenever it arrives.
 */
final class Server
{
    /** How long the editor must be quiet before diagnostics are sent, in seconds. */
    private const QUIET = 0.15;

    private const METHOD_NOT_FOUND = -32601;
    private const INTERNAL_ERROR = -32603;
    private const SERVER_NOT_INITIALIZED = -32002;

    private Workspace $workspace;

    /** @var array<string, true> The documents whose diagnostics are out of date, by URI. */
    private array $stale = [];

    private bool $vocabularyStale = false;

    private ?ApplicationVocabularyProcess $application = null;

    /** The last reading from the running application, kept while the next is read. */
    private ?VocabularyIndex $applicationVocabulary = null;

    /** The last reading from source, which the application's reading is merged over. */
    private ?VocabularyIndex $latestFromSource = null;

    private bool $initialized = false;

    private bool $shutdown = false;

    private ?int $exitCode = null;

    private bool $canWatchFiles = false;

    /** Whether the editor takes formatting registered for `.warrant` files only. */
    private bool $canRegisterFormatting = false;

    private int $nextRequestId = 1;

    /**
     * @param StdioTransport $transport How messages are read and written.
     * @param string $script The server's entry script, run again with
     *   `--vocabulary` to read the running application.
     * @param bool $canWait Whether the platform can wait on several streams at
     *   once; without it, input is read as it comes and the application is read
     *   to the end before going on.
     * @param resource|null $log Where the server notes what it is doing, which an
     *   editor shows in its output for the server; standard error when null.
     */
    public function __construct(
        private readonly StdioTransport $transport,
        private readonly string $script,
        private readonly bool $canWait = PHP_OS_FAMILY !== 'Windows',
        private $log = null,
    ) {
        $this->workspace = new Workspace;
    }

    /**
     * Serve until the editor says to exit or closes the connection, and return
     * the exit code: 0 after a shutdown, 1 otherwise.
     */
    public function run(): int
    {
        while (true) {
            while (($message = $this->transport->next()) !== null) {
                $this->handle($message);

                if ($this->exitCode !== null) {
                    $this->application?->stop();

                    return $this->exitCode;
                }
            }

            if (! $this->canWait) {
                $this->catchUp();

                if (! $this->transport->fill()) {
                    return $this->shutdown ? 0 : 1;
                }

                continue;
            }

            $read = [$this->transport->input(), ...($this->application?->streams() ?? [])];
            $write = null;
            $except = null;
            $waiting = $this->stale !== [] || $this->vocabularyStale;
            $timeout = $waiting ? self::QUIET : ($this->application !== null ? 0.5 : null);

            $ready = @stream_select($read, $write, $except, $timeout === null ? null : 0, $timeout === null ? null : (int) ($timeout * 1_000_000));

            if ($ready === false) {
                $this->catchUp();

                if (! $this->transport->fill()) {
                    return $this->shutdown ? 0 : 1;
                }

                continue;
            }

            if ($this->application !== null) {
                $this->readApplication();
            }

            if (in_array($this->transport->input(), $read, true)) {
                if (! $this->transport->fill()) {
                    $this->application?->stop();

                    return $this->shutdown ? 0 : 1;
                }

                continue;
            }

            if ($ready === 0) {
                $this->catchUp();
            }
        }
    }

    /**
     * Answer one message from the editor.
     *
     * @param array<string, mixed> $message A JSON-RPC request, notification or
     *   response.
     */
    public function handle(array $message): void
    {
        $method = $message['method'] ?? null;
        $id = $message['id'] ?? null;

        // A response to a request the server sent; nothing waits on it.
        if (! is_string($method)) {
            return;
        }

        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        try {
            if (! $this->initialized && $method !== 'initialize' && $method !== 'exit') {
                if ($id !== null) {
                    $this->transport->send(['id' => $id, 'error' => ['code' => self::SERVER_NOT_INITIALIZED, 'message' => 'The server has not been initialized.']]);
                }

                return;
            }

            $result = $this->dispatch($method, $params, $id !== null);

            if ($id !== null) {
                $this->transport->send(['id' => $id, 'result' => $result]);
            }
        } catch (MethodNotFound) {
            if ($id !== null) {
                $this->transport->send(['id' => $id, 'error' => ['code' => self::METHOD_NOT_FOUND, 'message' => "Unhandled method {$method}."]]);
            }
        } catch (Throwable $error) {
            $this->log("{$method} failed: {$error->getMessage()} at {$error->getFile()}:{$error->getLine()}");

            if ($id !== null) {
                $this->transport->send(['id' => $id, 'error' => ['code' => self::INTERNAL_ERROR, 'message' => $error->getMessage()]]);
            }
        }
    }

    /**
     * @param string $method The message's method.
     * @param array<string, mixed> $params Its parameters.
     * @param bool $isRequest Whether it expects an answer.
     *
     * @throws MethodNotFound For a request the server does not answer.
     */
    private function dispatch(string $method, array $params, bool $isRequest): mixed
    {
        $document = $this->documentOf($params);
        $position = is_array($params['position'] ?? null) ? $params['position'] : [];

        return match ($method) {
            'initialize' => $this->initialize($params),
            'initialized' => $this->afterInitialized(),
            'shutdown' => $this->shutDown(),
            'exit' => $this->exit(),
            'textDocument/didOpen' => $this->open($params),
            'textDocument/didChange' => $this->change($params),
            'textDocument/didClose' => $this->close($params),
            'workspace/didChangeWatchedFiles' => $this->filesChanged(),
            'textDocument/hover' => $document === null ? null : Hover::at($this->workspace, $document, $position),
            'textDocument/definition' => $document === null ? null : Definition::at($this->workspace, $document, $position),
            'textDocument/completion' => ['isIncomplete' => false, 'items' => $document === null ? [] : Completion::at($this->workspace, $document, $position)],
            'textDocument/documentSymbol' => $document === null ? [] : DocumentSymbols::of($this->workspace, $document),
            'textDocument/codeAction' => $document === null ? [] : [
                ...CodeActions::for($this->workspace, $document, is_array($params['context'] ?? null) ? $params['context'] : []),
                ...Formatting::codeActions($document, is_array($params['context'] ?? null) ? $params['context'] : []),
            ],
            'textDocument/formatting' => $document === null ? null : Formatting::edits($document),
            default => $isRequest ? throw new MethodNotFound : null,
        };
    }

    private function shutDown(): null
    {
        $this->shutdown = true;

        return null;
    }

    private function exit(): null
    {
        $this->exitCode = $this->shutdown ? 0 : 1;

        return null;
    }

    /**
     * A schema is a PHP file, so a changed PHP file means reading the schemas
     * again, once the editor is quiet.
     */
    private function filesChanged(): null
    {
        $this->vocabularyStale = true;

        return null;
    }

    /**
     * The handshake: what the server can do.
     *
     * @param array<string, mixed> $params The `initialize` request's parameters.
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $root = null;

        if (is_string($params['rootUri'] ?? null)) {
            $root = Uri::toPath($params['rootUri']);
        } elseif (is_array($params['workspaceFolders'] ?? null) && is_string($params['workspaceFolders'][0]['uri'] ?? null)) {
            $root = Uri::toPath($params['workspaceFolders'][0]['uri']);
        } elseif (is_string($params['rootPath'] ?? null)) {
            $root = $params['rootPath'];
        }

        $mode = VocabularyMode::tryFrom((string) ($params['initializationOptions']['vocabulary'] ?? 'auto')) ?? VocabularyMode::Auto;

        $this->workspace = new Workspace($root === null ? null : rtrim($root, '/\\'), $mode);
        $this->canWatchFiles = (bool) ($params['capabilities']['workspace']['didChangeWatchedFiles']['dynamicRegistration'] ?? false);
        $this->canRegisterFormatting = (bool) ($params['capabilities']['textDocument']['formatting']['dynamicRegistration'] ?? false);
        $this->initialized = true;

        return [
            'capabilities' => [
                'positionEncoding' => 'utf-16',
                'textDocumentSync' => ['openClose' => true, 'change' => 1],
                'hoverProvider' => true,
                'definitionProvider' => true,
                'documentSymbolProvider' => true,
                'completionProvider' => ['triggerCharacters' => [' ', '(', '@', '.', ',']],
                'codeActionProvider' => ['codeActionKinds' => ['quickfix', Formatting::KIND]],
                /* Registered later for `.warrant` files only when the editor can
                   take that, so it is never offered as a PHP formatter. */
                'documentFormattingProvider' => ! $this->canRegisterFormatting,
            ],
            'serverInfo' => ['name' => 'warrant-language-server'],
        ];
    }

    /**
     * Once the editor is ready: read the schemas, ask to hear when PHP files
     * change, since a schema is a PHP file, and offer formatting for `.warrant`
     * files.
     */
    private function afterInitialized(): null
    {
        $this->readVocabulary();
        $registrations = [];

        if ($this->canWatchFiles) {
            $registrations[] = [
                'id' => 'warrant-php-files',
                'method' => 'workspace/didChangeWatchedFiles',
                'registerOptions' => ['watchers' => [['globPattern' => '**/*.php'], ['globPattern' => '**/composer.lock']]],
            ];
        }

        if ($this->canRegisterFormatting) {
            $registrations[] = [
                'id' => 'warrant-formatting',
                'method' => 'textDocument/formatting',
                'registerOptions' => ['documentSelector' => [['language' => 'warrant'], ['pattern' => '**/*.warrant']]],
            ];
        }

        if ($registrations !== []) {
            $this->transport->send([
                'id' => 'warrant-'.$this->nextRequestId++,
                'method' => 'client/registerCapability',
                'params' => ['registrations' => $registrations],
            ]);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $params The `didOpen` parameters.
     */
    private function open(array $params): null
    {
        $item = is_array($params['textDocument'] ?? null) ? $params['textDocument'] : [];
        $uri = (string) ($item['uri'] ?? '');
        $path = Uri::toPath($uri);

        if ($path !== null) {
            $this->workspace->open(new Document($uri, $path, (string) ($item['text'] ?? ''), (int) ($item['version'] ?? 0)));
            $this->stale[$uri] = true;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $params The `didChange` parameters.
     */
    private function change(array $params): null
    {
        $uri = (string) ($params['textDocument']['uri'] ?? '');
        $document = $this->workspace->document($uri);
        $changes = is_array($params['contentChanges'] ?? null) ? $params['contentChanges'] : [];
        $last = $changes === [] ? null : $changes[array_key_last($changes)];

        if ($document !== null && is_array($last) && is_string($last['text'] ?? null)) {
            $this->workspace->open($document->withText($last['text'], (int) ($params['textDocument']['version'] ?? $document->version + 1)));
            $this->stale[$uri] = true;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $params The `didClose` parameters.
     */
    private function close(array $params): null
    {
        $uri = (string) ($params['textDocument']['uri'] ?? '');
        $this->workspace->close($uri);
        unset($this->stale[$uri]);
        $this->transport->send(['method' => 'textDocument/publishDiagnostics', 'params' => ['uri' => $uri, 'diagnostics' => []]]);

        return null;
    }

    /**
     * @param array<string, mixed> $params A request's parameters.
     */
    private function documentOf(array $params): ?Document
    {
        $uri = $params['textDocument']['uri'] ?? null;

        return is_string($uri) ? $this->workspace->document($uri) : null;
    }

    /**
     * Do what waits for a quiet moment: read the schemas again if a PHP file
     * changed, and send the diagnostics that are out of date.
     */
    private function catchUp(): void
    {
        if ($this->vocabularyStale) {
            $this->vocabularyStale = false;
            $this->readVocabulary();
        }

        foreach (array_keys($this->stale) as $uri) {
            $document = $this->workspace->document((string) $uri);

            if ($document !== null) {
                $this->transport->send([
                    'method' => 'textDocument/publishDiagnostics',
                    'params' => ['uri' => $document->uri, 'version' => $document->version, 'diagnostics' => Diagnostics::of($this->workspace, $document)],
                ]);
            }
        }

        $this->stale = [];
    }

    /**
     * Read the project's schemas from source, and in `auto` mode start reading
     * them from the running application too.
     */
    private function readVocabulary(): void
    {
        $root = $this->workspace->root;

        if ($root === null || $this->workspace->mode === VocabularyMode::Off) {
            return;
        }

        $fromSource = (new StaticSchemaScanner($root, ClassFileLocator::forProject($root)))->scan();
        $this->latestFromSource = $fromSource;
        $this->useVocabulary($this->applicationVocabulary === null ? $fromSource : VocabularyIndex::merge($this->applicationVocabulary, $fromSource));
        $this->log(sprintf('Read %d schema(s) and %d global schema(s) from source.', count($fromSource->schemas), count($fromSource->globals)));

        if ($this->workspace->mode !== VocabularyMode::Auto) {
            return;
        }

        $this->application?->stop();
        $this->application = ApplicationVocabularyProcess::start(PHP_BINARY, $this->script, $root);

        if ($this->application !== null && ! $this->canWait) {
            $this->application->wait();
            $this->readApplication();
        }
    }

    /**
     * Read what the application process has written, and once it has finished,
     * use its schemas.
     */
    private function readApplication(): void
    {
        if ($this->application === null || ! $this->application->read()) {
            return;
        }

        $read = $this->application->result();
        $error = $this->application->error();
        $this->application->stop();
        $this->application = null;

        if ($read === null) {
            $this->log('Could not read the schemas from the running application'.($error === null ? '.' : ": {$error}"));

            return;
        }

        $this->applicationVocabulary = $read;
        $this->useVocabulary(VocabularyIndex::merge($read, $this->latestFromSource ?? new VocabularyIndex));
        $this->log(sprintf('Read %d schema(s) and %d global schema(s) from the running application.', count($read->schemas), count($read->globals)));
    }

    /**
     * Check every open document against $vocabulary.
     *
     * @param VocabularyIndex $vocabulary The schemas.
     */
    private function useVocabulary(VocabularyIndex $vocabulary): void
    {
        $this->workspace->useVocabulary($vocabulary);

        foreach (array_keys($this->workspace->documents()) as $uri) {
            $this->stale[$uri] = true;
        }
    }

    /**
     * @param string $message What to note, one line.
     */
    private function log(string $message): void
    {
        fwrite($this->log ?? STDERR, "[warrant] {$message}\n");
    }
}
