<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Contracts;

use Iniznet\Mahout\Fields\OptionScreen;

/**
 * The host's declared option screens. Every admin surface the option context
 * owns — the settings page, its save entry, its write-failure notice — derives
 * from this collection and from nothing else, so a screen the host has not
 * declared does not exist anywhere. That is the whole of the opt-in:
 * `Admin\FieldsUiProvider` attaches nothing when no implementation is bound,
 * and attaches everything when a non-empty one is.
 *
 * The collection is the host's, the screen is the package's: the host owns the
 * declaration site (its config file, its loader, its validation of what a
 * settings page may carry), and the package owns the surface the screen is
 * registered from — `OptionScreen`, because the (page, group) pairing is the
 * fact every option surface here is built from. The opt-in and the
 * no-declaration case are one code path: the provider resolves this contract
 * exactly as it resolves `ContractsPanels`, and an absent binding, or an
 * empty collection, registers no page at all.
 *
 * @extends \IteratorAggregate<int, OptionScreen>
 */
interface OptionScreens extends \IteratorAggregate
{
    public function isEmpty(): bool;
}
