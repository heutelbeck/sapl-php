<?php

declare(strict_types=1);

namespace Sapl\Pdp;

/**
 * Endpoint route names exposed by the SAPL Node PDP.
 *
 * The HTTP transport prefixes each with {@see API_PREFIX}.
 */
enum PdpRoute: string
{
    case DECIDE_ONCE = 'decide-once';
    case DECIDE = 'decide';
    case MULTI_DECIDE = 'multi-decide';
    case MULTI_DECIDE_ALL = 'multi-decide-all';
    case MULTI_DECIDE_ALL_ONCE = 'multi-decide-all-once';

    public const string API_PREFIX = '/api/pdp/';

    public function path(): string
    {
        return self::API_PREFIX.$this->value;
    }
}
