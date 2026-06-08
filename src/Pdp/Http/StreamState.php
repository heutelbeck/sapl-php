<?php

declare(strict_types=1);

namespace Sapl\Pdp\Http;

use React\EventLoop\TimerInterface;
use React\Stream\ReadableStreamInterface;

/**
 * Mutable state for one streaming subscription's reconnect loop.
 *
 * Shared by reference across the connect, reconnect, and consumer-close
 * closures so they coordinate without an enclosing object per emission.
 */
final class StreamState
{
    public int $attempt = 0;
    public ?object $last = null;
    public bool $closed = false;
    public bool $reconnecting = false;
    public ?TimerInterface $timer = null;
    public ?ReadableStreamInterface $bodyStream = null;
}
