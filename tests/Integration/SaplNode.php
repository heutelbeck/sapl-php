<?php

declare(strict_types=1);

namespace Sapl\Tests\Integration;

use RuntimeException;
use Symfony\Component\HttpClient\HttpClient;
use Throwable;

/**
 * Manages a SAPL Node container for integration tests: a permit-all policy set,
 * HTTP with no auth on a free port. Skips the suite when the image is absent.
 */
final class SaplNode
{
    public const string IMAGE = 'ghcr.io/heutelbeck/sapl-node:4.1.2';

    private string $containerId = '';
    private readonly int $httpPort;
    private readonly string $policiesDir;

    public function __construct()
    {
        $this->httpPort = self::freePort();
        $this->policiesDir = self::writePolicies();
    }

    public static function imagePresent(): bool
    {
        exec('docker image inspect '.escapeshellarg(self::IMAGE).' 2>/dev/null', $output, $code);

        return 0 === $code;
    }

    public function baseUrl(): string
    {
        return 'http://127.0.0.1:'.$this->httpPort;
    }

    public function start(): void
    {
        $command = sprintf(
            'docker run -d -p 127.0.0.1:%d:8443 -v %s:/pdp/data:ro '
            .'-e SERVER_SSL_ENABLED=false -e SERVER_PORT=8443 -e SERVER_ADDRESS=0.0.0.0 '
            .'-e IO_SAPL_NODE_ALLOWNOAUTH=true '
            .'-e IO_SAPL_PDP_EMBEDDED_PDPCONFIGTYPE=DIRECTORY '
            .'-e IO_SAPL_PDP_EMBEDDED_POLICIESPATH=/pdp/data %s',
            $this->httpPort,
            escapeshellarg($this->policiesDir),
            escapeshellarg(self::IMAGE),
        );
        $this->containerId = trim((string) shell_exec($command));
        if ('' === $this->containerId) {
            throw new RuntimeException('Failed to start SAPL Node container');
        }
        $this->waitForReady(60.0);
    }

    public function stop(): void
    {
        if ('' !== $this->containerId) {
            exec('docker stop '.escapeshellarg($this->containerId).' 2>/dev/null');
        }
    }

    public function remove(): void
    {
        if ('' !== $this->containerId) {
            exec('docker rm -f '.escapeshellarg($this->containerId).' 2>/dev/null');
            $this->containerId = '';
        }
    }

    private function waitForReady(float $timeoutSeconds): void
    {
        $client = HttpClient::create();
        $url = $this->baseUrl().'/api/pdp/decide-once';
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            try {
                $response = $client->request('POST', $url, [
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => '{"subject":"_","action":"_","resource":"_"}',
                    'timeout' => 2.0,
                ]);
                if ($response->getStatusCode() >= 200) {
                    return;
                }
            } catch (Throwable) {
                usleep(500_000);
            }
        }

        throw new RuntimeException('SAPL Node did not become ready within '.$timeoutSeconds.'s');
    }

    private static function writePolicies(): string
    {
        $dir = sys_get_temp_dir().'/sapl-php-it-'.bin2hex(random_bytes(6));
        if (!mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create policy directory '.$dir);
        }
        file_put_contents(
            $dir.'/pdp.json',
            '{"algorithm": {"votingMode": "PRIORITY_PERMIT", "defaultDecision": "DENY", '
            .'"errorHandling": "ABSTAIN"}, "variables": {}}'."\n",
        );
        file_put_contents($dir.'/permit-all.sapl', 'policy "permit-all"'."\n".'permit'."\n");

        return $dir;
    }

    private static function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (false === $server) {
            throw new RuntimeException('Failed to allocate a free port: '.$errstr);
        }
        $name = stream_socket_get_name($server, false);
        fclose($server);
        if (false === $name) {
            throw new RuntimeException('Failed to read allocated port');
        }
        $colon = strrpos($name, ':');

        return false === $colon ? 0 : (int) substr($name, $colon + 1);
    }
}
