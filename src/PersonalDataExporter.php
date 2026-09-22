<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

use Iniznet\Mahout\Fields\Contracts\FieldReader;
use Iniznet\Mahout\Fields\Contracts\FieldRegistry;

/**
 * The export path for user-scoped fields. Only fields whose policy is
 * Export(label) appear; the label is the declaration's, never inferred from
 * the data. Values are read through the field layer -- the same reader every
 * surface uses -- never from a table and never through a raw meta call.
 *
 * One field per page: the callback's page parameter is the field index, so a
 * long field list cannot make one request unbounded. Core's contract fixes
 * the envelope: {data, done}.
 */
final readonly class PersonalDataExporter
{
    public function __construct(
        private FieldRegistry $registry,
        private FieldReader $reader,
    ) {
    }

    /** @return array{data: list<array<string, string>>, done: bool} */
    public function export(string $emailAddress, int $page = 1): array
    {
        $user = \get_user_by('email', $emailAddress);

        if (false === $user) {
            return ['data' => [], 'done' => true];
        }

        $fields = $this->fields();
        $field = $fields[$page - 1] ?? null;

        if (null === $field || null === $field->personalData) {
            return ['data' => [], 'done' => \count($fields) < $page];
        }

        $value = $this->reader->value($field->id, ObjectRef::user((int) $user->ID));

        return [
            'data' => [[
                'group_id' => 'mahout-fields',
                'group_label' => __('Field values', 'mahout-fields'),
                'item_id' => 'user-'.$user->ID,
                'name' => $field->personalData->label,
                'value' => null === $value ? '' : (string) $value,
            ]],
            'done' => \count($fields) <= $page,
        ];
    }

    /** @return list<Field> the user-context fields with an Export policy */
    private function fields(): array
    {
        $fields = [];

        foreach ($this->registry->groups() as $group) {
            if (ObjectContext::User !== $group->context) {
                continue;
            }

            foreach ($group->fields as $field) {
                if (null !== $field->personalData && $field->personalData->isExported()) {
                    $fields[] = $field;
                }
            }
        }

        return $fields;
    }
}
