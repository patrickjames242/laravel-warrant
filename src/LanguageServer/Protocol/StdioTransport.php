<?php

namespace Warrant\LanguageServer\Protocol;

/**
 * Reads and writes Language Server Protocol messages over a pair of streams:
 * each message a `Content-Length` header, a blank line, and that many bytes of
 * JSON.
 */
final class StdioTransport
{
    private string $buffer = '';

    /**
     * @param resource $input Where the editor's messages arrive.
     * @param resource $output Where the server's messages go.
     */
    public function __construct(
        private $input,
        private $output,
    ) {
    }

    /**
     * The stream the editor's messages arrive on, to wait on.
     *
     * @return resource
     */
    public function input()
    {
        return $this->input;
    }

    /**
     * Read whatever has arrived. False once the editor has closed the stream.
     */
    public function fill(): bool
    {
        $read = fread($this->input, 65536);

        if ($read === false || ($read === '' && feof($this->input))) {
            return false;
        }

        $this->buffer .= $read;

        return true;
    }

    /**
     * The next whole message that has arrived, or null when none has yet. A
     * message whose body is not JSON is skipped.
     *
     * @return array<string, mixed>|null
     */
    public function next(): ?array
    {
        while (true) {
            $headerEnd = strpos($this->buffer, "\r\n\r\n");

            if ($headerEnd === false) {
                return null;
            }

            if (preg_match('/^Content-Length:\s*(\d+)/mi', substr($this->buffer, 0, $headerEnd), $match) !== 1) {
                // A header without a length cannot be framed; it is dropped.
                $this->buffer = substr($this->buffer, $headerEnd + 4);

                continue;
            }

            $length = (int) $match[1];

            if (strlen($this->buffer) < $headerEnd + 4 + $length) {
                return null;
            }

            $body = substr($this->buffer, $headerEnd + 4, $length);
            $this->buffer = substr($this->buffer, $headerEnd + 4 + $length);
            $message = json_decode($body, true);

            if (is_array($message)) {
                return $message;
            }
        }
    }

    /**
     * Send $message to the editor.
     *
     * @param array<string, mixed> $message A JSON-RPC message.
     */
    public function send(array $message): void
    {
        $body = (string) json_encode(
            ['jsonrpc' => '2.0', ...$message],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        fwrite($this->output, 'Content-Length: '.strlen($body)."\r\n\r\n".$body);
        fflush($this->output);
    }
}
