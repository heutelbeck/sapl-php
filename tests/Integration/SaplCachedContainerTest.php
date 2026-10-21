<?php

declare(strict_types=1);

namespace Sapl\Tests\Integration;

use const PHP_BINARY;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class SaplCachedContainerTest extends TestCase
{
    public function testCachedContainerLoadsServiceProxiesInFreshProcesses(): void
    {
        $cacheDir = sys_get_temp_dir().'/sapl-cached-container-'.bin2hex(random_bytes(8));
        $filesystem = new Filesystem();
        $filesystem->mkdir($cacheDir);

        try {
            self::assertSame('read:42', $this->runService($cacheDir, 'permit', '42'));
            self::assertSame('read:43', $this->runService($cacheDir, 'permit', '43'));
            self::assertSame('denied', $this->runService($cacheDir, 'deny', '44'));
        } finally {
            $filesystem->remove($cacheDir);
        }
    }

    private function runService(string $cacheDir, string $decision, string $id): string
    {
        $code = <<<'PHP'
            require $argv[1];

            $kernel = new Sapl\Tests\Integration\Kernel\SaplTestKernel('cached_proxy', false);
            $kernel->boot();
            try {
                $pdp = $kernel->getContainer()->get(Sapl\Tests\Integration\Kernel\ConfigurableFakePdp::class);
                $pdp->decision = $argv[2] === 'permit'
                    ? Sapl\Api\AuthorizationDecision::permit()
                    : Sapl\Api\AuthorizationDecision::deny();
                $service = $kernel->getContainer()->get(Sapl\Tests\Integration\Kernel\TestService::class);
                try {
                    echo $service->read($argv[3]);
                } catch (Sapl\Pep\AccessDeniedException) {
                    echo 'denied';
                }
            } finally {
                $kernel->shutdown();
            }
            PHP;
        $process = new Process([
            PHP_BINARY, '-d', 'sys_temp_dir='.$cacheDir, '-r', $code,
            dirname(__DIR__, 2).'/vendor/autoload.php', $decision, $id,
        ]);
        $process->setTimeout(30);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());

        return $process->getOutput();
    }
}
