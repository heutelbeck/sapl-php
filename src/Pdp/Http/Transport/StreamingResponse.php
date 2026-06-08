<?php

declare(strict_types=1);

namespace Sapl\Pdp\Http\Transport;

use React\Stream\ReadableStreamInterface;

/**
 * A streaming HTTP response: status code and the response body as a readable
 * stream of bytes (consumed incrementally as Server-Sent-Events frames).
 */
final class StreamingResponse
{
    public function __construct(
        public readonly int $statusCode,
        public readonly ReadableStreamInterface $body,
    ) {
    }
}
