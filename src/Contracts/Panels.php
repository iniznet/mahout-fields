<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Contracts;

use Iniznet\Mahout\Fields\FieldPanel;

/**
 * The host's declared field panels. Every admin surface the field layer owns
 * — each metabox, the save entry, the REST read bindings, the write-failure
 * notice — derives from this collection and from nothing else, so a panel the
 * host has not declared does not exist anywhere. That is the whole of the
 * opt-in: `Admin\FieldsUiProvider` attaches nothing when no implementation is
 * bound, and attaches everything when a non-empty one is.
 *
 * The collection is the host's, the panel is the package's: the host owns the
 * declaration site (its config file, its loader, its validation of what a screen
 * may carry), and the package owns the pair a metabox is registered for —
 * `FieldPanel`, because the (post type, group) pairing is the fact every admin
 * surface here is built from. Iteration is part of the contract because the
 * REST read bindings are registered per post type at `rest_api_init`, when no
 * screen names one — the enumeration has to come from the declaration.
 *
 * @extends \IteratorAggregate<int, FieldPanel>
 */
interface Panels extends \IteratorAggregate
{
    public function isEmpty(): bool;

    /**
     * The panels declared for one post type, in declaration order.
     *
     * @return list<FieldPanel>
     */
    public function forPostType(string $postType): array;
}
