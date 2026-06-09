<?php

declare(strict_types=1);

namespace Sapl\Tests\Integration;

use React\Stream\ThroughStream;
use Sapl\Api\AuthorizationDecision;
use Sapl\Pep\AccessDeniedException;
use Sapl\Symfony\Proxy\SaplProxyMarker;
use Sapl\Tests\Integration\Kernel\ConfigurableFakePdp;
use Sapl\Tests\Integration\Kernel\SaplTestKernel;
use Sapl\Tests\Integration\Kernel\TestService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Boots the SAPL bundle in a real kernel and drives the enforcement subscriber
 * end to end: a PERMIT decision lets the controller run, a non-PERMIT decision is
 * mapped to HTTP 403.
 */
final class SaplBundleKernelTest extends KernelTestCase
{
    public static function setUpBeforeClass(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir().'/sapl-kernel-test');
    }

    protected static function getKernelClass(): string
    {
        return SaplTestKernel::class;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // The booted kernel pushes one exception handler; pop it so PHPUnit's
        // own handler is restored.
        restore_exception_handler();
    }

    public function testPreEnforcePermitRunsTheController(): void
    {
        self::assertSame(200, $this->handle('/pre', AuthorizationDecision::permit())->getStatusCode());
    }

    public function testPreEnforceDenyIsForbidden(): void
    {
        self::assertSame(403, $this->handle('/pre', AuthorizationDecision::deny())->getStatusCode());
    }

    public function testPostEnforcePermitReturnsTheResult(): void
    {
        self::assertSame(200, $this->handle('/post', AuthorizationDecision::permit())->getStatusCode());
    }

    public function testPostEnforceDenyIsForbidden(): void
    {
        self::assertSame(403, $this->handle('/post', AuthorizationDecision::deny())->getStatusCode());
    }

    public function testServiceMethodPreEnforcePermitRuns(): void
    {
        $service = $this->service(AuthorizationDecision::permit());

        self::assertInstanceOf(SaplProxyMarker::class, $service);
        self::assertSame('read:42', $service->read('42'));
    }

    public function testServiceMethodPreEnforceDenyThrows(): void
    {
        $service = $this->service(AuthorizationDecision::deny());

        $this->expectException(AccessDeniedException::class);

        $service->read('42');
    }

    public function testServiceMethodStreamEnforcePassesItemsWhilePermittedAndDropsAfterDeny(): void
    {
        self::bootKernel(['debug' => false]);
        $pdp = self::getContainer()->get(ConfigurableFakePdp::class);
        self::assertInstanceOf(ConfigurableFakePdp::class, $pdp);
        $service = self::getContainer()->get(TestService::class);
        self::assertInstanceOf(TestService::class, $service);

        $rap = new ThroughStream();
        $service->beatSource = $rap;
        $items = [];
        $service->beats()->on('data', static function (mixed $value) use (&$items): void {
            $items[] = $value;
        });

        $pdp->decisions->write(AuthorizationDecision::permit());
        $rap->write('tick');
        $pdp->decisions->write(AuthorizationDecision::deny());
        $rap->write('after-deny');

        self::assertSame(['tick'], $items);
    }

    private function service(AuthorizationDecision $decision): TestService
    {
        self::bootKernel(['debug' => false]);
        $pdp = self::getContainer()->get(ConfigurableFakePdp::class);
        self::assertInstanceOf(ConfigurableFakePdp::class, $pdp);
        $pdp->decision = $decision;
        $service = self::getContainer()->get(TestService::class);
        self::assertInstanceOf(TestService::class, $service);

        return $service;
    }

    private function handle(string $path, AuthorizationDecision $decision): Response
    {
        $kernel = self::bootKernel(['debug' => false]);
        $pdp = self::getContainer()->get(ConfigurableFakePdp::class);
        self::assertInstanceOf(ConfigurableFakePdp::class, $pdp);
        $pdp->decision = $decision;

        return $kernel->handle(Request::create($path));
    }
}
