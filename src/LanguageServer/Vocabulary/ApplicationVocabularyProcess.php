<?php

namespace Warrant\LanguageServer\Vocabulary;

/**
 * A child process reading the project's schemas from the running application
 * ({@see ApplicationSchemaReader}), which the server reads alongside the editor's
 * messages so that a slow boot never holds them up.
 */
final class ApplicationVocabularyProcess
{
    /** How long the application may take to boot and answer, in seconds. */
    private const TIMEOUT = 30;

    /** @var resource */
    private $process;

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    private string $output = '';

    private float $startedAt;

    /**
     * @param resource $process The process.
     * @param resource $stdout Its standard output.
     * @param resource $stderr Its standard error.
     */
    private function __construct($process, $stdout, $stderr)
    {
        $this->process = $process;
        $this->stdout = $stdout;
        $this->stderr = $stderr;
        $this->startedAt = microtime(true);
    }

    /**
     * Start reading the schemas of the project at $root, or null when the
     * project is not a Laravel application or the process cannot start.
     *
     * @param string $php The PHP binary to run.
     * @param string $script The language server's entry script, which reads the
     *   schemas when given `--vocabulary`.
     * @param string $root Absolute path of the project.
     */
    public static function start(string $php, string $script, string $root): ?self
    {
        if (! is_file($root.'/bootstrap/app.php')) {
            return null;
        }

        $process = @proc_open(
            [$php, $script, '--vocabulary', $root],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
        );

        if (! is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return new self($process, $pipes[1], $pipes[2]);
    }

    /**
     * The streams to wait on for the process's output.
     *
     * @return list<resource>
     */
    public function streams(): array
    {
        return [$this->stdout, $this->stderr];
    }

    /**
     * Read whatever output is waiting. True once the process has finished, or
     * has run out of time and been stopped.
     */
    public function read(): bool
    {
        $this->output .= (string) stream_get_contents($this->stdout);
        // Standard error is drained so the child never blocks on a full pipe.
        stream_get_contents($this->stderr);

        if (feof($this->stdout)) {
            return true;
        }

        if (microtime(true) - $this->startedAt > self::TIMEOUT) {
            $this->stop();

            return true;
        }

        return false;
    }

    /**
     * Wait for the process to finish, reading its output as it comes. For
     * platforms where the server cannot wait on several streams at once.
     */
    public function wait(): void
    {
        while (! $this->read()) {
            usleep(50_000);
        }
    }

    /**
     * The schemas the process read, or null when it failed: the application
     * would not boot, or the output was not what the reader writes.
     */
    public function result(): ?VocabularyIndex
    {
        $begin = strrpos($this->output, ApplicationSchemaReader::BEGIN);

        if ($begin === false) {
            return null;
        }

        $data = json_decode(trim(substr($this->output, $begin + strlen(ApplicationSchemaReader::BEGIN))), true);

        if (! is_array($data) || ! is_array($data['schemas'] ?? null)) {
            return null;
        }

        return ApplicationSchemaReader::indexFrom($data['schemas']);
    }

    /**
     * What the process said went wrong, when it said.
     */
    public function error(): ?string
    {
        $begin = strrpos($this->output, ApplicationSchemaReader::BEGIN);
        $data = $begin === false ? null : json_decode(trim(substr($this->output, $begin + strlen(ApplicationSchemaReader::BEGIN))), true);

        return is_array($data) && is_string($data['error'] ?? null) ? $data['error'] : null;
    }

    public function stop(): void
    {
        if (is_resource($this->stdout)) {
            fclose($this->stdout);
        }

        if (is_resource($this->stderr)) {
            fclose($this->stderr);
        }

        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }
}
