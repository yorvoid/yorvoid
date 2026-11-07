<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

/**
 * Why a decision couldn't be made (XP-01): every Indeterminate carries one or more. New cases can come
 * in a minor release, so a match of yours on them needs a default arm.
 *
 * @api
 */
enum IndeterminateReason: string
{
    /** A store couldn't be reached: connection refused or lost, a timeout, a failover in progress (XP-02) */
    case StoreUnreachable = 'store_unreachable';

    /** A forbid that matches couldn't be evaluated, so it can't be ruled out (06 §3, row 2) */
    case UnknownForbid = 'unknown_forbid';

    /** A signature the decision depends on couldn't be verified */
    case UnverifiableSignature = 'unverifiable_signature';

    /** A record the decision needs is missing, or a policy has nothing to decide with */
    case MissingData = 'missing_data';

    /** Records the decision depends on contradict each other */
    case ConflictingRecords = 'conflicting_records';

    /** A policy element or check failed with an error */
    case EvaluationError = 'evaluation_error';
}
