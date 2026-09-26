<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

use Iniznet\Mahout\Db\Contracts\SqlConnection;
use Iniznet\Mahout\Db\Exception\UnboundedStatement;
use Iniznet\Mahout\Db\Table;
use Iniznet\Mahout\Fields\Contracts\FieldQuery as FieldQueryContract;
use Iniznet\Mahout\Fields\Contracts\FieldRegistry;
use Iniznet\Mahout\Fields\Exception\InvalidFieldDefinition;
use Iniznet\Mahout\Fields\Exception\InvalidStorageCombination;

/**
 * The bounded field query builder over the generic value table.
 *
 * Every identifier in every statement -- the table name, the value column,
 * the kind and field columns -- is read back from a declared schema object or
 * a closed enum; every value is bound through a placeholder; every statement
 * carries an explicit LIMIT, the aggregate included: the aggregate counts over a
 * capped inner read, so no shape in this class has a cost that grows with the size
 * of the range it answers. There is no other statement shape in this class and no
 * string argument reaches one (ADR-0011).
 */
final readonly class FieldQuery implements FieldQueryContract
{
    private const string AGGREGATE = 'aggregate';

    public function __construct(
        private FieldRegistry $registry,
        private SqlConnection $connection,
        private Table $values,
        private Table $leaves,
    ) {
    }

    /**
     * The contract promises a positive limit; this implementation is the one
     * place the promise is enforced, so its parameter stays plain int.
     */
    #[\Override]
    public function postIds(string $fieldId, Operator $operator, string|int|float|bool|null $value, int $limit): array
    {
        if ($limit < 1) {
            throw UnboundedStatement::forLimit($this->values->name->value, $limit);
        }

        if ($this->isMemberQualified($fieldId)) {
            return $this->leafIds($fieldId, $operator, $value, $limit);
        }

        [$column, $placeholder, $comparand] = $this->binding($fieldId, $value);

        $statement = \sprintf(
            'SELECT %s FROM %s WHERE %s = %%d AND %s = %%s AND %s %s %s LIMIT %%d',
            FieldValuesTable::objectIdColumn(),
            $this->values->name->value,
            FieldValuesTable::objectKindColumn(),
            FieldValuesTable::fieldIdColumn(),
            $column,
            $operator->comparison(),
            $placeholder,
        );

        $rows = $this->connection->rowsPrepared($statement, ObjectKind::Post->value, $fieldId, $comparand, $limit);

        return \array_map(static fn (array $row): int => (int) $row[FieldValuesTable::objectIdColumn()], $rows);
    }

    #[\Override]
    public function countUpTo(string $fieldId, Operator $operator, string|int|float|bool|null $value, int $ceiling): int
    {
        if ($ceiling < 1) {
            throw UnboundedStatement::forLimit($this->values->name->value, $ceiling);
        }

        // The bound cannot sit on an aggregate, so it goes one level down. The scan
        // then costs at most $ceiling rows whatever the range holds, and the answer
        // saturates at $ceiling instead of the statement becoming unbounded.
        if ($this->isMemberQualified($fieldId)) {
            [$root, $member, $column, $placeholder, $comparand] = $this->leafBinding($fieldId, $value);

            $statement = \sprintf(
                'SELECT COUNT(*) AS aggregate FROM (SELECT 1 FROM %s WHERE %s = %%d AND %s = %%s AND %s = %%s AND %s %s %s LIMIT %%d) AS capped',
                $this->leaves->name->value,
                FieldLeavesTable::objectKindColumn(),
                FieldLeavesTable::groupIdColumn(),
                FieldLeavesTable::memberColumn(),
                $column,
                $operator->comparison(),
                $placeholder,
            );

            $rows = $this->connection->rowsPrepared($statement, ObjectKind::Post->value, $root, $member, $comparand, $ceiling);

            return (int) ($rows[0][self::AGGREGATE] ?? 0);
        }

        [$column, $placeholder, $comparand] = $this->binding($fieldId, $value);

        $statement = \sprintf(
            'SELECT COUNT(*) AS aggregate FROM (SELECT 1 FROM %s WHERE %s = %%d AND %s = %%s AND %s %s %s LIMIT %%d) AS capped',
            $this->values->name->value,
            FieldValuesTable::objectKindColumn(),
            FieldValuesTable::fieldIdColumn(),
            $column,
            $operator->comparison(),
            $placeholder,
        );

        $rows = $this->connection->rowsPrepared($statement, ObjectKind::Post->value, $fieldId, $comparand, $ceiling);

        return (int) ($rows[0][self::AGGREGATE] ?? 0);
    }

    #[\Override]
    public function orderedIds(string $fieldId, OrderDirection $direction, int $limit): array
    {
        if ($limit < 1) {
            throw UnboundedStatement::forLimit($this->values->name->value, $limit);
        }

        if ($this->isMemberQualified($fieldId)) {
            [$root, $member, $column, $placeholder, $comparand] = $this->leafBinding($fieldId, null);

            $statement = \sprintf(
                'SELECT %s FROM %s WHERE %s = %%d AND %s = %%s AND %s = %%s AND %s IS NOT NULL ORDER BY %s %s LIMIT %%d',
                FieldLeavesTable::objectIdColumn(),
                $this->leaves->name->value,
                FieldLeavesTable::objectKindColumn(),
                FieldLeavesTable::groupIdColumn(),
                FieldLeavesTable::memberColumn(),
                $column,
                $column,
                $direction->clause(),
            );

            $rows = $this->connection->rowsPrepared($statement, ObjectKind::Post->value, $root, $member, $limit);

            return \array_map(static fn (array $row): int => (int) $row[FieldLeavesTable::objectIdColumn()], $rows);
        }

        $column = $this->valueColumn($fieldId);

        $statement = \sprintf(
            'SELECT %s FROM %s WHERE %s = %%d AND %s = %%s AND %s IS NOT NULL ORDER BY %s %s LIMIT %%d',
            FieldValuesTable::objectIdColumn(),
            $this->values->name->value,
            FieldValuesTable::objectKindColumn(),
            FieldValuesTable::fieldIdColumn(),
            $column,
            $column,
            $direction->clause(),
        );

        $rows = $this->connection->rowsPrepared($statement, ObjectKind::Post->value, $fieldId, $limit);

        return \array_map(static fn (array $row): int => (int) $row[FieldValuesTable::objectIdColumn()], $rows);
    }

    /**
     * The value column the field's type is stored in, and the placeholder and
     * canonical value to bind against it. A field whose type has no value
     * column -- the repeater, whose items live in their own table -- is
     * refused; a comparison against a column that does not exist is not a
     * query, it is a bug in the caller's declaration.
     *
     * @return array{0: string, 1: string, 2: string|int}
     */
    private function binding(string $fieldId, string|int|float|bool|null $value): array
    {
        $column = $this->valueColumn($fieldId);

        $comparand = match (true) {
            \is_bool($value) => $value ? 1 : 0,
            \is_float($value) => \number_format($value, 6, '.', ''),
            default => $value ?? '',
        };

        if (FieldValuesTable::intColumn() === $column) {
            return [$column, '%d', (int) $comparand];
        }

        return [$column, '%s', (string) $comparand];
    }

    /**
     * The member-qualified leaf query: post ids whose repeater carries at
     * least one leaf of the named member matching the comparison. The
     * query_path index — group, member, text prefix — serves the scan; a
     * queried repeater is the developer's declared choice, at the cost the
     * contract states.
     *
     * @return list<int>
     */
    private function leafIds(string $fieldId, Operator $operator, string|int|float|bool|null $value, int $limit): array
    {
        [$root, $member, $column, $placeholder, $comparand] = $this->leafBinding($fieldId, $value);

        $statement = \sprintf(
            'SELECT %s FROM %s WHERE %s = %%d AND %s = %%s AND %s = %%s AND %s %s %s LIMIT %%d',
            FieldLeavesTable::objectIdColumn(),
            $this->leaves->name->value,
            FieldLeavesTable::objectKindColumn(),
            FieldLeavesTable::groupIdColumn(),
            FieldLeavesTable::memberColumn(),
            $column,
            $operator->comparison(),
            $placeholder,
        );

        $rows = $this->connection->rowsPrepared($statement, ObjectKind::Post->value, $root, $member, $comparand, $limit);

        $ids = [];

        foreach ($rows as $row) {
            $id = (int) $row[FieldLeavesTable::objectIdColumn()];

            if (!\in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * The qualified id's parts: the root repeater and the queried member,
     * resolved against the declaration — the member must be a declared
     * scalar leaf of the subtree, and the root must bind the leaves table.
     *
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string|int}
     */
    private function leafBinding(string $qualified, string|int|float|bool|null $value): array
    {
        [$root, $memberId] = explode('.', $qualified, 2);

        $registration = $this->registry->resolve($root);
        $field = $registration->field;

        if (!$field instanceof RepeaterField) {
            throw InvalidFieldDefinition::unknownMember($root, $memberId);
        }

        if (StorageTarget::Table !== $registration->storage) {
            throw InvalidStorageCombination::queryAgainstMeta($root);
        }

        $member = $this->memberNamed($field, $memberId);

        if (null === $member) {
            throw InvalidFieldDefinition::unknownMember($root, $memberId);
        }

        $column = FieldLeavesTable::columnFor($member->type());

        if (null === $column) {
            throw InvalidFieldDefinition::unknownMember($root, $memberId);
        }

        $comparand = match (true) {
            \is_bool($value) => $value ? 1 : 0,
            \is_float($value) => \number_format($value, 6, '.', ''),
            default => $value ?? '',
        };

        // A scalar-item repeater stores its leaves with an empty member: the
        // item field is the leaf, and no member name is stored beside it.
        $stored = $field->item instanceof Field && $field->item->id === $member->id ? '' : $memberId;

        if (FieldLeavesTable::intColumn() === $column) {
            return [$root, $stored, $column, '%d', (int) $comparand];
        }

        return [$root, $stored, $column, '%s', (string) $comparand];
    }

    /**
     * The declared scalar member one qualified id names, anywhere in the
     * subtree, or null when the id names nothing — the caller refuses.
     */
    private function memberNamed(RepeaterField $root, string $memberId): ?Field
    {
        foreach ($root->members() as $member) {
            if (!$member instanceof RepeaterField) {
                if ($member->id === $memberId) {
                    return $member;
                }

                continue;
            }

            $nested = $this->memberNamed($member, $memberId);

            if (null !== $nested) {
                return $nested;
            }
        }

        return null;
    }

    private function isMemberQualified(string $fieldId): bool
    {
        return str_contains($fieldId, '.');
    }

    private function valueColumn(string $fieldId): string
    {
        $registration = $this->registry->resolve($fieldId);

        if (StorageTarget::Table !== $registration->storage) {
            throw InvalidStorageCombination::queryAgainstMeta($fieldId);
        }

        $column = FieldValuesTable::columnFor($registration->field->type());

        if (null === $column) {
            throw InvalidFieldDefinition::noValueColumn($fieldId);
        }

        return $column;
    }
}
