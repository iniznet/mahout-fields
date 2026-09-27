<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Unit;

use Iniznet\Mahout\Db\Table;
use Iniznet\Mahout\Fields\FieldItemsTable;
use Iniznet\Mahout\Fields\FieldLeavesTable;
use Iniznet\Mahout\Fields\FieldValuesTable;
use Iniznet\Mahout\Fields\Tests\TestCase;
use Iniznet\Mahout\Kernel\RuntimeIdentity;

/**
 * The promise {@see RuntimeIdentity::LONGEST_IDENTIFIER_SUFFIX} makes is that it names
 * the widest SQL identifier this family composes — and the kernel cannot prove it, because
 * the dependency runs the other way. This package can: it owns the tables.
 *
 * So the assertion is made against the package's own declarations rather than a literal.
 * A fourth table wider than this one fails here, which is the moment the budget in the
 * kernel has to be updated; without this test the budget would be quietly wrong and the
 * failure would surface as an illegal identifier inside a migration on a site with data.
 *
 * @internal
 */
final class IdentifierBudgetTest extends TestCase
{
    /** The site's own prefix, so the measurement is of this installation's names. */
    private const string PREFIX = 'wptests_';

    public function testTheStoredValueTableIsTheWidestNameThisPackageComposes(): void
    {
        $suffixes = array_map(
            static fn (Table $table): string => self::suffixOf($table->name->value),
            [
                FieldValuesTable::table(self::PREFIX, self::charset()),
                FieldLeavesTable::table(self::PREFIX, self::charset()),
                FieldItemsTable::table(self::PREFIX, self::charset()),
            ],
        );

        $widest = '';

        foreach ($suffixes as $suffix) {
            if (\strlen($suffix) > \strlen($widest)) {
                $widest = $suffix;
            }
        }

        self::assertSame('field_values', $widest, 'the widest table this package declares changed shape; update RuntimeIdentity::LONGEST_IDENTIFIER_SUFFIX in the same change');
        self::assertSame($widest, RuntimeIdentity::LONGEST_IDENTIFIER_SUFFIX, 'the kernel budgets against a name this package no longer composes');
    }

    /**
     * The budget is arithmetic on the widest name, so a slug that exactly fills MySQL's
     * limit for this prefix is accepted and the next character is refused. Measured here
     * rather than only in the kernel, because the prefix that ships with a WordPress
     * install is what makes the number real.
     */
    public function testTheWidestNameFitsExactlyAndOverflowsByOne(): void
    {
        $identity = RuntimeIdentity::fromSlug('howdah');
        $allowance = $identity->allowance(self::PREFIX);

        self::assertSame(36, $allowance);

        RuntimeIdentity::fromSlug(str_repeat('a', $allowance))->assertFits(self::PREFIX);

        $this->expectException(\Iniznet\Mahout\Kernel\Exception\InvalidRuntimeIdentity::class);

        RuntimeIdentity::fromSlug(str_repeat('a', $allowance + 1))->assertFits(self::PREFIX);
    }

    /**
     * The suffix a table name carries, with the package's own head removed.
     */
    private static function suffixOf(string $name): string
    {
        $head = self::PREFIX.'mahout_';

        self::assertStringStartsWith($head, $name, 'a table this package owns stopped carrying the package head');

        return substr($name, strlen($head));
    }

    private static function charset(): string
    {
        global $wpdb;

        return (string) $wpdb->get_charset_collate();
    }
}
