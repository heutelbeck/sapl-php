<?php

declare(strict_types=1);

namespace Sapl\Pdp\Http;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\Stream\ReadableStreamInterface;
use Sapl\Api\AuthorizationDecision;
use Sapl\Api\AuthorizationSubscription;
use Sapl\Api\IdentifiableAuthorizationDecision;
use Sapl\Api\MultiAuthorizationDecision;
use Sapl\Api\MultiAuthorizationSubscription;
use Sapl\Pdp\Auth\TokenProvider;
use Sapl\Pdp\Http\Transport\ReactStreamingHttpTransport;
use Sapl\Pdp\Http\Transport\StreamingHttpTransport;
use Sapl\Pdp\Http\Transport\SymfonyHttpTransport;
use Sapl\Pdp\Http\Transport\TransportException;
use Sapl\Pdp\Http\Transport\UnaryHttpTransport;
use Sapl\Pdp\PdpRoute;
use Sapl\Pdp\PolicyDecisionPoint;
use Sapl\Pdp\Reconnect\BackoffPolicy;

/**
 * HTTP / SSE transport for the SAPL Node PDP API.
 *
 * One-shot requests run on a blocking unary transport and fail closed to
 * INDETERMINATE without retry. Streaming requests run on ReactPHP and never
 * terminate on a transport condition: they seed INDETERMINATE and reconnect
 * with bounded exponential backoff. No transport error is ever raised to the
 * caller.
 */
final class HttpPdpClient implements PolicyDecisionPoint
{
    private const array LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1'];

    private const string ERROR_BASE_URL_REQUIRED = 'HttpPdpClient requires a non-empty base URL.';
    private const string ERROR_MIXED_AUTH = 'PDP authentication conflict: configure exactly one of token, username/secret, or tokenProvider.';
    private const string ERROR_NON_LOOPBACK_PLAINTEXT = 'PDP base URL uses plain HTTP against a non-loopback host. Use HTTPS or run the PDP on localhost.';
    private const string ERROR_PARTIAL_BASIC_AUTH = 'PDP Basic Auth requires both username and secret.';

    private readonly LoggerInterface $logger;
    private readonly DecisionParser $parser;
    private readonly BackoffPolicy $backoff;
    private readonly UnaryHttpTransport $unaryTransport;
    private readonly StreamingHttpTransport $streamingTransport;
    private readonly ?TokenProvider $tokenProvider;
    private readonly ?string $staticAuthorization;
    private readonly float $streamFirstItemTimeoutSeconds;
    private readonly float $streamInactivityTimeoutSeconds;

    /** @var array<string, string> route value to fully qualified URL */
    private readonly array $urls;

    public function __construct(
        HttpPdpClientOptions $options,
        ?UnaryHttpTransport $unaryTransport = null,
        ?StreamingHttpTransport $streamingTransport = null,
        ?DecisionParser $parser = null,
        ?BackoffPolicy $backoff = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->validateBaseUrl($options->baseUrl);
        $this->validateAuth($options);

        $this->logger = $logger ?? new NullLogger();
        $this->parser = $parser ?? new DecisionParser($this->logger);
        $this->backoff = $backoff ?? new BackoffPolicy(
            $options->retryBaseDelaySeconds,
            $options->retryMaxDelaySeconds,
        );
        $this->unaryTransport = $unaryTransport ?? new SymfonyHttpTransport($options->timeoutSeconds);
        $this->streamingTransport = $streamingTransport ?? new ReactStreamingHttpTransport(verifyPeer: $options->verifyPeer);
        $this->tokenProvider = $options->tokenProvider;
        $this->staticAuthorization = $this->buildStaticAuthorization($options);
        $this->streamFirstItemTimeoutSeconds = $options->timeoutSeconds;
        $this->streamInactivityTimeoutSeconds = $options->streamInactivityTimeoutSeconds;
        $this->urls = $this->buildUrls($options->baseUrl);
    }

    public function decideOnce(AuthorizationSubscription $subscription): AuthorizationDecision
    {
        $raw = $this->requestUnary($this->urls[PdpRoute::DECIDE_ONCE->value], $subscription->toArray());

        return null === $raw ? AuthorizationDecision::indeterminate() : $this->parser->parseDecision($raw);
    }

    public function multiDecideAllOnce(MultiAuthorizationSubscription $subscription): MultiAuthorizationDecision
    {
        $body = $this->requestUnaryRaw($this->urls[PdpRoute::MULTI_DECIDE_ALL_ONCE->value], $subscription->toArray());
        if (null === $body) {
            return new MultiAuthorizationDecision();
        }

        return $this->parser->parseMultiJson($body) ?? new MultiAuthorizationDecision();
    }

    public function decide(AuthorizationSubscription $subscription): ReadableStreamInterface
    {
        return $this->stream(
            $this->urls[PdpRoute::DECIDE->value],
            $subscription->toArray(),
            fn (mixed $raw): AuthorizationDecision => $this->parser->parseDecision($raw),
            static fn (): array => [AuthorizationDecision::indeterminate()],
        );
    }

    public function multiDecide(MultiAuthorizationSubscription $subscription): ReadableStreamInterface
    {
        $ids = $subscription->subscriptionIds();

        return $this->stream(
            $this->urls[PdpRoute::MULTI_DECIDE->value],
            $subscription->toArray(),
            fn (mixed $raw): ?object => $this->parser->parseIdentifiable($raw),
            static fn (): array => array_map(
                static fn (string $id): IdentifiableAuthorizationDecision => new IdentifiableAuthorizationDecision(
                    $id,
                    AuthorizationDecision::indeterminate(),
                ),
                $ids,
            ),
        );
    }

    public function multiDecideAll(MultiAuthorizationSubscription $subscription): ReadableStreamInterface
    {
        $ids = $subscription->subscriptionIds();

        return $this->stream(
            $this->urls[PdpRoute::MULTI_DECIDE_ALL->value],
            $subscription->toArray(),
            fn (mixed $raw): ?object => $this->parser->parseMulti($raw),
            static function () use ($ids): array {
                $decisions = [];
                foreach ($ids as $id) {
                    $decisions[$id] = AuthorizationDecision::indeterminate();
                }

                return [new MultiAuthorizationDecision($decisions)];
            },
        );
    }

    public function close(): void
    {
        // Streaming resources are owned per subscription and released when the
        // consumer closes the returned stream. The unary transport holds none.
    }

    /**
     * @param array<string, mixed> $body
     */
    private function requestUnary(string $url, array $body): mixed
    {
        $raw = $this->requestUnaryRaw($url, $body);
        if (null === $raw) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (JSON_ERROR_NONE !== json_last_error()) {
            $this->logger->error('sapl.pdp_response_not_json', ['url' => $url]);

            return null;
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function requestUnaryRaw(string $url, array $body, bool $retried = false): ?string
    {
        $json = json_encode($body);
        if (false === $json) {
            $this->logger->error('sapl.pdp_request_body_not_encodable', ['url' => $url]);

            return null;
        }
        try {
            $response = $this->unaryTransport->send('POST', $url, $this->headers(false), $json);
        } catch (TransportException $error) {
            $this->logger->error('sapl.pdp_request_failed', ['url' => $url, 'error' => $error->getMessage()]);

            return null;
        }
        if ($response->statusCode >= 400) {
            $this->logger->error('sapl.pdp_returned_http_error', ['url' => $url, 'status' => $response->statusCode]);
            if ((401 === $response->statusCode || 403 === $response->statusCode)
                && $this->invalidateToken()
                && !$retried) {
                return $this->requestUnaryRaw($url, $body, true);
            }

            return null;
        }

        return $response->body;
    }

    /**
     * @param array<string, mixed>     $body
     * @param callable(mixed): ?object $parse
     * @param callable(): list<object> $seed
     */
    private function stream(string $url, array $body, callable $parse, callable $seed): ReadableStreamInterface
    {
        $reconnector = new StreamReconnector(
            $this->streamingTransport,
            $this->backoff,
            $this->logger,
            $url,
            (string) json_encode($body),
            $this->headers(true),
            $parse(...),
            $seed(...),
            function (): void {
                $this->invalidateToken();
            },
            $this->streamFirstItemTimeoutSeconds,
            $this->streamInactivityTimeoutSeconds,
        );

        return $reconnector->start();
    }

    /**
     * @return array<string, string>
     */
    private function headers(bool $streaming): array
    {
        $headers = ['Content-Type' => 'application/json'];
        if ($streaming) {
            $headers['Accept'] = 'text/event-stream';
        }
        $authorization = $this->resolveAuthorization();
        if (null !== $authorization) {
            $headers['Authorization'] = $authorization;
        }

        return $headers;
    }

    private function resolveAuthorization(): ?string
    {
        if (null !== $this->tokenProvider) {
            return 'Bearer '.$this->tokenProvider->accessToken();
        }

        return $this->staticAuthorization;
    }

    private function invalidateToken(): bool
    {
        if (null === $this->tokenProvider) {
            return false;
        }
        $this->tokenProvider->invalidate();

        return true;
    }

    private function buildStaticAuthorization(HttpPdpClientOptions $options): ?string
    {
        if (null !== $options->token && '' !== $options->token) {
            return 'Bearer '.$options->token;
        }
        if (null !== $options->username && null !== $options->secret) {
            return 'Basic '.base64_encode($options->username.':'.$options->secret);
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function buildUrls(string $baseUrl): array
    {
        $base = rtrim($baseUrl, '/');
        $urls = [];
        foreach (PdpRoute::cases() as $route) {
            $urls[$route->value] = $base.$route->path();
        }

        return $urls;
    }

    private function validateBaseUrl(string $baseUrl): void
    {
        if ('' === $baseUrl) {
            throw new InvalidArgumentException(self::ERROR_BASE_URL_REQUIRED);
        }
        if ('http' !== parse_url($baseUrl, PHP_URL_SCHEME)) {
            return;
        }
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (!is_string($host) || !in_array(strtolower($host), self::LOOPBACK_HOSTS, true)) {
            throw new InvalidArgumentException(self::ERROR_NON_LOOPBACK_PLAINTEXT.' URL: '.$baseUrl);
        }
    }

    private function validateAuth(HttpPdpClientOptions $options): void
    {
        $hasToken = null !== $options->token && '' !== $options->token;
        $hasUser = null !== $options->username && '' !== $options->username;
        $hasSecret = null !== $options->secret && '' !== $options->secret;
        $hasBasic = $hasUser || $hasSecret;
        $hasProvider = null !== $options->tokenProvider;

        if ((int) $hasToken + (int) $hasBasic + (int) $hasProvider > 1) {
            throw new InvalidArgumentException(self::ERROR_MIXED_AUTH);
        }
        if ($hasBasic && !($hasUser && $hasSecret)) {
            throw new InvalidArgumentException(self::ERROR_PARTIAL_BASIC_AUTH);
        }
    }
}
