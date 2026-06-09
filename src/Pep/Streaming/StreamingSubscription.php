<?php

declare(strict_types=1);

namespace Sapl\Pep\Streaming;

use React\Stream\ReadableStreamInterface;
use React\Stream\ThroughStream;
use Sapl\Api\AuthorizationDecision;
use Throwable;

/**
 * Wires one streaming subscription: feeds the {@see StreamingEnforcementDriver}
 * from the PDP decision stream and the RAP item stream, and renders the driver's
 * emissions onto an output stream.
 *
 * A value emission becomes a `data` event; a complete ends the output; a denial
 * errors and closes it; a boundary transition is written as data only when
 * transition signalling is enabled. Closing the output cancels the subscription.
 */
final class StreamingSubscription
{
    private readonly ThroughStream $out;
    private bool $done = false;

    public function __construct(
        private readonly StreamingEnforcementDriver $driver,
        private readonly ReadableStreamInterface $decisions,
        private readonly ReadableStreamInterface $rap,
        private readonly bool $signalTransitions,
    ) {
        $this->out = new ThroughStream();
    }

    public function start(): ReadableStreamInterface
    {
        $this->decisions->on('data', function (mixed $decision): void {
            if ($decision instanceof AuthorizationDecision) {
                $this->render($this->driver->onDecision($decision));
            }
        });
        $this->decisions->on('error', function (Throwable $error): void {
            $this->render($this->driver->onPdpError($error));
        });
        $this->rap->on('data', function (mixed $item): void {
            $this->render($this->driver->onItem($item));
        });
        $this->rap->on('end', function (): void {
            $this->render($this->driver->onRapComplete());
        });
        $this->rap->on('error', function (Throwable $error): void {
            $this->render($this->driver->onRapError($error));
        });
        $this->out->on('close', function (): void {
            $this->onConsumerClose();
        });

        return $this->out;
    }

    /**
     * @param list<Emission> $emissions
     */
    private function render(array $emissions): void
    {
        if ($this->done) {
            return;
        }
        foreach ($emissions as $emission) {
            if ($emission instanceof Emit) {
                $this->out->write($emission->value);
            } elseif ($emission instanceof EmitComplete) {
                $this->finish();
            } elseif ($emission instanceof EmitError) {
                $this->fail($emission->error);
            } elseif ($emission instanceof EmitTransition && $this->signalTransitions) {
                $this->out->write($emission->reason);
            }
            if ($this->done) {
                break;
            }
        }
    }

    private function finish(): void
    {
        $this->done = true;
        $this->closeInputs();
        $this->out->end();
    }

    private function fail(Throwable $error): void
    {
        $this->done = true;
        $this->closeInputs();
        $this->out->emit('error', [$error]);
        $this->out->close();
    }

    private function onConsumerClose(): void
    {
        if ($this->done) {
            return;
        }
        $this->done = true;
        $this->driver->onCancel();
        $this->closeInputs();
    }

    private function closeInputs(): void
    {
        $this->decisions->close();
        $this->rap->close();
    }
}
