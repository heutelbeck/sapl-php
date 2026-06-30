<?php

declare(strict_types=1);

namespace Sapl\Tests\Unit\Pdp\Http\Transport;

use PHPUnit\Framework\TestCase;
use Sapl\Pdp\Http\Transport\SymfonyHttpTransport;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Latency-budget bounding of a blocking one-off PDP call.
 *
 * Spring caps every one-off decision with a flat total timeout
 * (.timeout(5s) -> onErrorReturn(INDETERMINATE)) so the caller's latency
 * budget holds no matter how the server stalls. A trickling server that
 * keeps resetting the inactivity timer must not be able to drag a blocking
 * decideOnce/multiDecideAllOnce past that budget (CR-PHP-03).
 */
final class SymfonyHttpTransportTest extends TestCase
{
    private const float BUDGET_SECONDS = 2.5;

    public function testOneOffCallBoundsTotalDurationNotOnlyIdleTime(): void
    {
        $options = $this->captureRequestOptions();

        self::assertSame(
            self::BUDGET_SECONDS,
            $options['max_duration'] ?? 0.0,
            'A trickling server must be capped by a total-duration budget, not only the inactivity timeout.',
        );
    }

    public function testOneOffCallStillBoundsIdleTimeBetweenChunks(): void
    {
        $options = $this->captureRequestOptions();

        self::assertSame(
            self::BUDGET_SECONDS,
            $options['timeout'] ?? null,
            'A silent server must still be caught by the inactivity timeout.',
        );
    }

    /**
     * @return array<string, mixed> the options the transport handed to the HTTP client
     */
    private function captureRequestOptions(): array
    {
        $captured = [];
        $client = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$captured): MockResponse {
                $captured = $options;

                return new MockResponse('{"decision":"PERMIT"}', ['http_code' => 200]);
            },
        );

        $transport = new SymfonyHttpTransport(self::BUDGET_SECONDS, $client);
        $transport->send('POST', 'http://localhost:8443/api/pdp/decide-once', [], '{}');

        /** @var array<string, mixed> $options */
        $options = $captured;

        return $options;
    }
}
