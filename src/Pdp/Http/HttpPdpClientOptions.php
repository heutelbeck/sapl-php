<?php

declare(strict_types=1);

namespace Sapl\Pdp\Http;

use Sapl\Pdp\Auth\TokenProvider;
use Sapl\Pdp\Reconnect\BackoffPolicy;

/**
 * Configuration for {@see HttpPdpClient}.
 *
 * Authentication options are mutually exclusive: pass exactly one of token,
 * (username + secret), or tokenProvider. Pass none when targeting a SAPL Node
 * configured to allow unauthenticated access.
 */
final class HttpPdpClientOptions
{
    public const float DEFAULT_TIMEOUT_SECONDS = 5.0;

    public function __construct(
        public readonly string $baseUrl,
        public readonly ?string $token = null,
        public readonly ?string $username = null,
        public readonly ?string $secret = null,
        public readonly ?TokenProvider $tokenProvider = null,
        public readonly float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        public readonly float $retryBaseDelaySeconds = BackoffPolicy::DEFAULT_BASE_SECONDS,
        public readonly float $retryMaxDelaySeconds = BackoffPolicy::DEFAULT_CAP_SECONDS,
        public readonly bool $verifyPeer = true,
    ) {
    }
}
