<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Contracts;

/**
 * The save boundary's request adapter. The save lifecycle reads the submitted
 * panel through this contract and through nothing else, so the classic form
 * path and a test fake share one shape and no superglobal is ever read inside
 * this package.
 *
 * A host adapts its own request object to this contract -- the theme's
 * Request is the one superglobal reader -- and binds it under this id, which
 * is how Admin\FieldsUiProvider's save handler receives it.
 */
interface RequestInput
{
    /**
     * Whether the named key was submitted at all. The foreign-form guard asks
     * exactly this of the panel's nonce field: a save_post that did not come
     * from the panel never carries it.
     */
    public function has(string $key): bool;

    /**
     * A submitted scalar, unslashed; null when the key is absent.
     */
    public function string(string $key): ?string;

    /**
     * The panel's submitted values, keyed by group id then field id. A
     * repeater's value is the item list; an absent field is absent from the
     * map, and an empty string is submitted as an empty string -- the field's
     * sanitiser, not the adapter, decides what it means.
     *
     * @return array<string, array<string, string|list<string>|null>>
     */
    public function groups(): array;

    /**
     * The expected-state hash each submitted group carries, keyed by group id.
     *
     * @return array<string, string>
     */
    public function hashes(): array;

    /**
     * A request parameter: the URL's query string or the submitted body, the
     * adapter owning the merge. The option screen's active tab is URL state
     * that the page's render and its save both read through here -- the one
     * boundary, so no superglobal is read inside this package.
     */
    public function param(string $key): ?string;
}
