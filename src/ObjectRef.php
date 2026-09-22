<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

/**
 * One object a field value belongs to: its context and its id.
 *
 * A value object, not a service: it resolves no collaborator and reads no
 * global. The context is carried beside the id so a reader can refuse a
 * mismatched call (a user field read with a post id) instead of writing to
 * whatever the id happens to name.
 */
final readonly class ObjectRef
{
    private function __construct(
        public ObjectContext $context,
        public int $id,
    ) {
    }

    public static function post(int $id): self
    {
        return new self(ObjectContext::Post, $id);
    }

    public static function user(int $id): self
    {
        return new self(ObjectContext::User, $id);
    }

    public static function term(int $id): self
    {
        return new self(ObjectContext::Term, $id);
    }

    /** The option context has no object; its key is the field's own identity. */
    public static function option(): self
    {
        return new self(ObjectContext::Option, 0);
    }

    /**
     * The value-table discriminator, or null for the option context: options
     * are singletons read by key and never rows.
     */
    public function objectKind(): ?ObjectKind
    {
        return ObjectKind::fromContext($this->context);
    }

    public function key(): string
    {
        return $this->context->value.':'.$this->id;
    }
}
