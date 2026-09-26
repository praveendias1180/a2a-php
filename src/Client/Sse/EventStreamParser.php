<?php

declare(strict_types=1);

namespace A2A\Client\Sse;

/**
 * Incremental Server-Sent Events parser (W3C / WHATWG event stream format).
 *
 * Mirrors a2a-python: parse_sse_stream() in
 * src/a2a/client/transports/http_helpers.py. Python reads whole lines from
 * httpx; here bytes arrive in arbitrary chunks, so the parser keeps a buffer
 * and handles lines, and even a CRLF pair, split across chunks.
 *
 * Same rules as Python: a blank line ends an event, an event is emitted only
 * if it had at least one `data` line, lines starting with ":" are comments
 * (keep-alives), one leading space after the colon is stripped, and `id` /
 * `retry` are ignored. Lines may end in LF, CRLF or CR. A final event with no
 * terminating blank line is discarded, as the spec says.
 *
 * @internal Not covered by the 1.x backward-compatibility promise; may change in any release.
 */
final class EventStreamParser
{
    private string $buffer = '';
    private string $eventName = 'message';
    /** @var list<string> */
    private array $data = [];
    private bool $atStart = true;

    /**
     * @return list<SseEvent> the events completed by this chunk
     */
    public function feed(string $chunk): array
    {
        if ($this->atStart && $chunk !== '') {
            $this->atStart = false;
            if (str_starts_with($chunk, "\u{FEFF}")) {
                $chunk = substr($chunk, 3);
            }
        }
        $this->buffer .= $chunk;

        return $this->drain(false);
    }

    /**
     * Call at end of stream. Processes a last unterminated line; an event that
     * never saw its blank line is dropped.
     *
     * @return list<SseEvent>
     */
    public function finish(): array
    {
        $events = $this->drain(true);
        $this->eventName = 'message';
        $this->data = [];

        return $events;
    }

    /**
     * @return list<SseEvent>
     */
    private function drain(bool $final): array
    {
        $events = [];
        while (true) {
            $length = strcspn($this->buffer, "\r\n");
            if ($length === strlen($this->buffer)) {
                if ($final && $this->buffer !== '') {
                    $line = $this->buffer;
                    $this->buffer = '';
                    $event = $this->processLine($line);
                    if ($event !== null) {
                        $events[] = $event;
                    }
                }

                break;
            }
            $terminator = $this->buffer[$length];
            if ($terminator === "\r" && $length + 1 === strlen($this->buffer) && !$final) {
                // Could be the first half of a CRLF split across chunks: wait.
                break;
            }
            $line = substr($this->buffer, 0, $length);
            $consume = $length + 1;
            if ($terminator === "\r" && ($this->buffer[$length + 1] ?? '') === "\n") {
                $consume++;
            }
            $this->buffer = substr($this->buffer, $consume);

            $event = $this->processLine($line);
            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    private function processLine(string $line): ?SseEvent
    {
        if ($line === '') {
            $event = $this->data !== [] ? new SseEvent($this->eventName, implode("\n", $this->data)) : null;
            $this->eventName = 'message';
            $this->data = [];

            return $event;
        }
        if ($line[0] === ':') {
            return null;
        }

        $parts = explode(':', $line, 2);
        $key = $parts[0];
        $value = $parts[1] ?? '';
        if (str_starts_with($value, ' ')) {
            $value = substr($value, 1);
        }

        if ($key === 'event') {
            $this->eventName = $value;
        } elseif ($key === 'data') {
            $this->data[] = $value;
        }

        return null;
    }
}
