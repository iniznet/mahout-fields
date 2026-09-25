<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

use Iniznet\Mahout\Fields\Exception\InvalidFieldWrite;

/**
 * A repeater leaf's address: the chain from the root repeater through item
 * positions and member ids, ending at the leaf. The grammar is the storage
 * shape — one scalar per address, no envelope, no nesting in storage.
 *
 *     tags.2                    scalar-item repeater, item 2
 *     credits.0.role            composite repeater, item 0, member role
 *     sections.0.blocks.1       nested repeater, item 1 of blocks in item 0
 *     sections.0.blocks.1.label the same leaf of a composite nested item
 *
 * A position is a canonical unsigned integer (no leading zeros); a member
 * matches the field-id pattern. The full address — root id and chain — is at
 * most {@see MAX_BYTES}, the cap wp_postmeta's meta_key and the leaves
 * table's address column share, so one grammar serves both targets.
 *
 * A static pure grammar holds no state and resolves no collaborator, which is
 * the contract's own permitted category for static access.
 */
final readonly class LeafAddress
{
    public const int MAX_BYTES = 191;

    private const string PART_PATTERN = '/^(?:[1-9][0-9]*|0|[a-z][a-z0-9_]*)$/';

    private function __construct(
        public string $full,
        public string $root,
        public string $relative,
        public string $member,
    ) {
    }

    /**
     * Parse and validate a full address. The root is the segment before the
     * first separator; the member is the trailing segment when it is a name
     * rather than a position.
     *
     * @throws InvalidFieldWrite when the grammar is broken or the address is past the cap
     */
    public static function of(string $full): self
    {
        if (strlen($full) > self::MAX_BYTES) {
            throw InvalidFieldWrite::addressTooLong(self::rootOf($full));
        }

        $parts = explode('.', $full);

        foreach ($parts as $part) {
            if ('' === $part || 1 !== preg_match(self::PART_PATTERN, $part)) {
                throw InvalidFieldWrite::badRepeaterAddress(self::rootOf($full));
            }
        }

        $root = $parts[0];
        $last = $parts[count($parts) - 1];
        $member = \ctype_digit($last) ? '' : $last;

        return new self(
            $full,
            $root,
            implode('.', array_slice($parts, 1)),
            $member,
        );
    }

    /** The relative chain: the address without the root segment. */
    public static function relative(string $full): string
    {
        $address = self::of($full);

        return $address->relative;
    }

    /** The leaf's member name, or '' for a scalar-item leaf. */
    public static function memberOf(string $full): string
    {
        return self::of($full)->member;
    }

    /** The position at one nesting level of a relative chain, read from the front. */
    public static function positionAt(string $relative, int $level): int
    {
        $parts = explode('.', $relative);
        $index = $level * 2;

        if (!isset($parts[$index]) || !\ctype_digit($parts[$index])) {
            throw InvalidFieldWrite::badRepeaterAddress($relative);
        }

        return (int) $parts[$index];
    }

    /** The root segment alone, safe to name in a refusal before validation. */
    private static function rootOf(string $full): string
    {
        $root = explode('.', $full)[0];

        return '' === $root ? $full : $root;
    }

    /**
     * Address order: positions compare numerically at their level, members
     * lexicographically, level by level. A lexical byte comparison would put
     * item 10 before item 2; the grammar's structure is what orders rows.
     */
    public static function compare(string $a, string $b): int
    {
        $left = explode('.', $a);
        $right = explode('.', $b);

        $count = min(count($left), count($right));

        for ($index = 0; $index < $count; ++$index) {
            $one = $left[$index];
            $two = $right[$index];

            if (\ctype_digit($one) && \ctype_digit($two)) {
                $order = (int) $one <=> (int) $two;
            } else {
                $order = $one <=> $two;
            }

            if (0 !== $order) {
                return $order;
            }
        }

        return count($left) <=> count($right);
    }
}
