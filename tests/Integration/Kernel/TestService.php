<?php

declare(strict_types=1);

namespace Sapl\Tests\Integration\Kernel;

use React\Stream\ReadableStreamInterface;
use React\Stream\ThroughStream;
use Sapl\Symfony\PostEnforce;
use Sapl\Symfony\PreEnforce;
use Sapl\Symfony\StreamEnforce;

/**
 * A domain service (not a controller) whose methods carry enforcement attributes,
 * to verify the compiler-pass proxy. Not final, so the generated proxy can extend
 * it.
 */
class TestService
{
    /** The item stream returned by {@see beats()}, set by the test before the call. */
    public ?ThroughStream $beatSource = null;

    #[PreEnforce(action: 'read', resource: 'thing')]
    public function read(string $id): string
    {
        return 'read:'.$id;
    }

    /**
     * @return array<string, bool>
     */
    #[PostEnforce(action: 'readResult')]
    public function readResult(): array
    {
        return ['ok' => true];
    }

    #[StreamEnforce(action: 'beats')]
    public function beats(): ReadableStreamInterface
    {
        return $this->beatSource ?? new ThroughStream();
    }
}
