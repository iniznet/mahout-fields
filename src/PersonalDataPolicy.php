<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

/**
 * The closed set of personal-data policies a user-scoped field can declare.
 * There is no default: the decision is visible in code, at the declaration.
 */
enum PersonalDataPolicy: string
{
    /** The value is exported under the declared label. */
    case Export = 'export';

    /** The value is deleted on an erasure request. */
    case Erase = 'erase';

    /** The value is passed through wp_privacy_anonymize_data() under the declared type. */
    case Anonymize = 'anonymize';

    /** The value is retained, and the reason is reported to the requester. */
    case Retain = 'retain';

    /** The field stores no personal data; the declaration carries the justification. */
    case NotPersonal = 'not-personal';
}
