<?php

declare(strict_types=1);

namespace Sapl\Api;

/**
 * Authorization decision verbs as defined by the SAPL protocol.
 */
enum Decision: string
{
    case PERMIT = 'PERMIT';
    case DENY = 'DENY';
    case INDETERMINATE = 'INDETERMINATE';
    case NOT_APPLICABLE = 'NOT_APPLICABLE';
    case SUSPEND = 'SUSPEND';
}
