<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

use Iniznet\Mahout\Fields\Exception\InvalidFieldId;

/**
 * One declared field group: an id, the object context every field shares, and
 * the fields. The group is data; every cross-field rule runs at registration,
 * in the registry, so a future construction site inherits the rules by
 * registering instead of copying checks.
 */
final readonly class FieldGroup
{
    private const int MAX_ID_LENGTH = 64;

    private const string ID_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /**
     * @param non-empty-string $id
     * @param list<Field>      $fields
     *
     * @throws InvalidFieldId when the group id violates the naming convention
     */
    public function __construct(
        public string $id,
        public ObjectContext $context,
        public array $fields,
    ) {
        if (strlen($id) > self::MAX_ID_LENGTH) {
            throw InvalidFieldId::tooLong('group', $id, self::MAX_ID_LENGTH);
        }

        if (1 !== preg_match(self::ID_PATTERN, $id)) {
            throw InvalidFieldId::malformed('group', $id);
        }
    }
}
