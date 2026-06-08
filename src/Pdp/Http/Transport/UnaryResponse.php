<?php

declare(strict_types=1);

namespace Sapl\Pdp\Http\Transport;

/**
 * A buffered HTTP response: status code and the full body as a string.
 */
final class UnaryResponse
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body,
    ) {
    }
}
