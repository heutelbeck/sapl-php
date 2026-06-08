<?php

declare(strict_types=1);

namespace Sapl\Pdp\Auth;

/**
 * Supplies bearer tokens for PDP authentication and invalidates a cached token
 * when the PDP rejects it (HTTP 401 / 403), so the next request fetches a fresh
 * one.
 */
interface TokenProvider
{
    public function accessToken(): string;

    public function invalidate(): void;
}
