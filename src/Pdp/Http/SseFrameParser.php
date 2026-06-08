<?php

declare(strict_types=1);

namespace Sapl\Pdp\Http;

/**
 * Incremental Server-Sent-Events frame parser.
 *
 * Bytes are pushed as they arrive; complete lines are split off the buffer,
 * `data:` prefixes stripped, comments and blanks skipped, and each remaining
 * line decoded as JSON. Frames that are not valid JSON are dropped. The buffer
 * is capped to bound memory against a frame that never terminates.
 */
final class SseFrameParser
{
    public const int DEFAULT_MAX_BUFFER_BYTES = 65_536;

    private string $buffer = '';

    public function __construct(
        private readonly int $maxBufferBytes = self::DEFAULT_MAX_BUFFER_BYTES,
    ) {
    }

    /**
     * Push a chunk and return any complete decoded JSON frames it produced.
     *
     * @return list<mixed> decoded frames in arrival order
     *
     * @throws SseBufferOverflowException when the buffer exceeds the cap
     */
    public function push(string $chunk): array
    {
        $this->buffer .= $chunk;
        if (strlen($this->buffer) > $this->maxBufferBytes) {
            $this->buffer = '';

            throw new SseBufferOverflowException('SSE frame exceeded buffer cap');
        }

        $frames = [];
        while (false !== ($newline = strpos($this->buffer, "\n"))) {
            $line = substr($this->buffer, 0, $newline);
            $this->buffer = substr($this->buffer, $newline + 1);

            $trimmed = trim($line);
            if ('' === $trimmed || str_starts_with($trimmed, ':')) {
                continue;
            }
            $data = str_starts_with($trimmed, 'data:') ? trim(substr($trimmed, 5)) : $trimmed;
            if ('' === $data) {
                continue;
            }
            $decoded = json_decode($data, true);
            if (JSON_ERROR_NONE !== json_last_error()) {
                continue;
            }
            $frames[] = $decoded;
        }

        return $frames;
    }
}
