<?php

declare(strict_types=1);

namespace Sapl\Api;

/**
 * A subscription sent to the PDP describing the authorization context.
 *
 * The secrets field is transmitted to the PDP but must never appear in logs;
 * use {@see toLoggableArray()} for log output.
 */
final class AuthorizationSubscription
{
    public function __construct(
        public readonly mixed $subject = null,
        public readonly mixed $action = null,
        public readonly mixed $resource = null,
        public readonly mixed $environment = null,
        public readonly mixed $secrets = null,
    ) {
    }

    /**
     * Serialize for transmission to the PDP, including secrets.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [
            'subject' => $this->subject,
            'action' => $this->action,
            'resource' => $this->resource,
        ];
        if (null !== $this->environment) {
            $result['environment'] = $this->environment;
        }
        if (null !== $this->secrets) {
            $result['secrets'] = $this->secrets;
        }

        return $result;
    }

    /**
     * Serialize for logging, excluding secrets.
     *
     * @return array<string, mixed>
     */
    public function toLoggableArray(): array
    {
        $result = [
            'subject' => $this->subject,
            'action' => $this->action,
            'resource' => $this->resource,
        ];
        if (null !== $this->environment) {
            $result['environment'] = $this->environment;
        }

        return $result;
    }
}
