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
 * carries an explicit LIMIT except the aggregate, whose bound is the
 * field_id index's own range scan. There is no other statement shape in this
 * class and no string argument reaches one (ADR-0011).
 */
final readonly class FieldQuery implements FieldQueryContract
{
    private const string AGGREGATE = 'aggregate';

    public function __construct(
        private FieldRegistry $registry,
        private SqlConnection $connection,
        private Table $values,
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
    public function count(string $fieldId, Operator $operator, string|int|float|bool|null $value): int
    {
        [$column, $placeholder, $comparand] = $this->binding($fieldId, $value);

        $statement = \sprintf(
            'SELECT COUNT(*) AS aggregate FROM %s WHERE %s = %%d AND %s = %%s AND %s %s %s',
            $this->values->name->value,
            FieldValuesTable::objectKindColumn(),
            FieldValuesTable::fieldIdColumn(),
            $column,
            $operator->comparison(),
            $placeholder,
        );

        $rows = $this->connection->rowsPrepared($statement, ObjectKind::Post->value, $fieldId, $comparand);

        return (int) ($rows[0][self::AGGREGATE] ?? 0);
    }

    #[\Override]
    public function orderedIds(string $fieldId, OrderDirection $direction, int $limit): array
    {
        if ($limit < 1) {
            throw UnboundedStatement::forLimit($this->values->name->value, $limit);
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
