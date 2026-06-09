<?php

declare(strict_types=1);

namespace Sapl\Pep\Streaming;

/**
 * Discriminator for a denial. The causes are isomorphic from the machine's
 * perspective (each terminates the subscription); the kind selects the denial
 * message and audit diagnostics.
 */
enum DenyKind
{
    /** INDETERMINATE from the PDP. */
    case INDETERMINATE;

    /** NOT_APPLICABLE from the PDP. */
    case NO_POLICY_APPLICABLE;

    /** PERMIT, but the plan's decision-scoped enforcement failed. */
    case PERMIT_NOT_ENFORCEABLE;

    /** An explicit DENY from the PDP. */
    case POLICY_DENIED;
}
