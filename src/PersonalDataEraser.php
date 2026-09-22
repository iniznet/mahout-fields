<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

use Iniznet\Mahout\Fields\Contracts\FieldReader;
use Iniznet\Mahout\Fields\Contracts\FieldRegistry;
use Iniznet\Mahout\Fields\Contracts\FieldWriter;

/**
 * The erasure path for user-scoped fields. Erase deletes through the writer
 * -- the same delete the panel uses, hooks and all; Anonymize passes the
 * stored value through wp_privacy_anonymize_data() under the policy's
 * declared type and stores the result through the writer, so sanitisation
 * stays owned; Retain is reported, with the policy's reason, and nothing is
 * written. The paths read through the reader and write through the writer;
 * no raw meta call, no direct table statement.
 *
 * One field per page: the callback's page parameter is the field index, so a
 * long field list cannot make one request unbounded and a failure stops at
 * the field it failed on. Core's contract fixes the envelope.
 */
final readonly class PersonalDataEraser
{
    public function __construct(
        private FieldRegistry $registry,
        private FieldReader $reader,
        private FieldWriter $writer,
    ) {
    }

    /** @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool} */
    public function erase(string $emailAddress, int $page = 1): array
    {
        $user = \get_user_by('email', $emailAddress);

        if (false === $user) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }

        $fields = $this->fields();
        $field = $fields[$page - 1] ?? null;
        $object = ObjectRef::user((int) $user->ID);

        if (null === $field || null === $field->personalData) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => \count($fields) < $page];
        }

        $policy = $field->personalData;
        $messages = [];
        $removed = false;
        $retained = false;

        if ($policy->isErased()) {
            $this->writer->delete($field->id, $object);
            $removed = true;
        } elseif ($policy->isAnonymized()) {
            $current = $this->reader->value($field->id, $object);

            if (null !== $current) {
                $this->writer->set($field->id, $object, \wp_privacy_anonymize_data($policy->anonymizeType, (string) $current));
                $removed = true;
            }
        } else {
            $messages[] = \sprintf(
                /* translators: 1: field id, 2: the retention reason declared on the field */
                __('Field "%1$s" was retained: %2$s', 'mahout-fields'),
                $field->id,
                $policy->reason,
            );
            $retained = true;
        }

        return ['items_removed' => $removed, 'items_retained' => $retained, 'messages' => $messages, 'done' => \count($fields) <= $page];
    }

    /** @return list<Field> the user-context fields a request can act on */
    private function fields(): array
    {
        $fields = [];

        foreach ($this->registry->groups() as $group) {
            if (ObjectContext::User !== $group->context) {
                continue;
            }

            foreach ($group->fields as $field) {
                if (
                    null !== $field->personalData
                    && \in_array($field->personalData->policy, [PersonalDataPolicy::Erase, PersonalDataPolicy::Anonymize, PersonalDataPolicy::Retain], true)
                ) {
                    $fields[] = $field;
                }
            }
        }

        return $fields;
    }
}
