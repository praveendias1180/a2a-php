<?php

declare(strict_types=1);

namespace A2A\Server\Routes\Sse;

use Psr\Http\Message\StreamInterface;

/**
 * A PSR-7 body that produces Server-Sent Events as they happen, pulled from
 * a generator of ready-made SSE chunks.
 *
 * Any PSR-7 emitter can send it (each read() returns the next event), but
 * to flush every event immediately and notice disconnects, emit responses
 * with ResponseEmitter.
 *
 * PHP-specific: Python uses sse-starlette's EventSourceResponse.
 */
final class SseStream implements StreamInterface
{
    private bool $started = false;

    private bool $closed = false;

    /**
     * @param \Generator<int, string, mixed, void> $chunks
     * @param bool $drainOnDisconnect keep pulling (so the agent keeps working) after the client goes away;
     *                                used for SendStreamingMessage, not for SubscribeToTask
     */
    public function __construct(
        private readonly \Generator $chunks,
        public readonly bool $drainOnDisconnect = false,
    ) {}

    /**
     * @return \Generator<int, string, mixed, void>
     */
    public function chunks(): \Generator
    {
        while (!$this->eof()) {
            $chunk = $this->read(0);
            if ($chunk !== '') {
                yield $chunk;
            }
        }
    }

    /**
     * Called by the emitter when the client disconnected.
     */
    public function clientDisconnected(): void
    {
        if ($this->drainOnDisconnect) {
            while (!$this->eof()) {
                $this->read(0);
            }
        }
        $this->close();
    }

    public function __toString(): string
    {
        $out = '';
        foreach ($this->chunks() as $chunk) {
            $out .= $chunk;
        }

        return $out;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function detach()
    {
        $this->close();

        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        throw new \RuntimeException('SSE streams are not seekable');
    }

    public function eof(): bool
    {
        if ($this->closed) {
            return true;
        }
        if (!$this->started) {
            return false;
        }

        return !$this->chunks->valid();
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new \RuntimeException('SSE streams are not seekable');
    }

    public function rewind(): void
    {
        throw new \RuntimeException('SSE streams are not seekable');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('SSE streams are read-only');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        if ($this->closed) {
            return '';
        }
        if (!$this->started) {
            $this->started = true;
            $this->chunks->current();
        } else {
            $this->chunks->next();
        }

        if (!$this->chunks->valid()) {
            return '';
        }

        return (string) $this->chunks->current();
    }

    public function getContents(): string
    {
        return $this->__toString();
    }

    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }

    /**
     * One SSE frame. Multi-line data is split across `data:` lines.
     */
    public static function event(string $data, ?string $event = null): string
    {
        $frame = $event !== null ? "event: {$event}\n" : '';
        foreach (explode("\n", $data) as $line) {
            $frame .= "data: {$line}\n";
        }

        return $frame . "\n";
    }

    public static function keepAlive(): string
    {
        return ": keep-alive\n\n";
    }
}
