<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Admin;

/**
 * The closed set of save refusals. Each maps to its diagnostics level and to
 * the REST status the route reports.
 */
enum SaveRefusalReason: string
{
    case PostLocked = 'post_locked';
    case AuthorizationDenied = 'authorization_denied';
    case NonceFailed = 'nonce_failed';
    case FieldShape = 'field_shape';
    case Validation = 'validation';
    case ConcurrentEditLost = 'concurrent_edit_lost';
    case WriteFailed = 'write_failed';
}
