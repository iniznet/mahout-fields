<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

/**
 * One field's personal-data policy. Every user-scoped field declares one at
 * registration and the registry refuses a user-context field without it --
 * the decision must be visible in code, never inferred from the data.
 *
 * A value object: it carries the policy and its one parameter, and enforces
 * the pairing at construction, so an Anonymize without a type cannot exist.
 */
final readonly class PersonalData
{
    private function __construct(
        public PersonalDataPolicy $policy,
        public string $label = '',
        public string $anonymizeType = '',
        public string $reason = '',
    ) {
    }

    /** The value is exported under this label. */
    public static function export(string $label): self
    {
        return new self(policy: PersonalDataPolicy::Export, label: $label);
    }

    /** The value is deleted on request. */
    public static function erase(): self
    {
        return new self(policy: PersonalDataPolicy::Erase);
    }

    /**
     * @param string $type a wp_privacy_anonymize_data() type: email, url, ip,
     *                     date, text or longtext
     */
    public static function anonymize(string $type): self
    {
        return new self(policy: PersonalDataPolicy::Anonymize, anonymizeType: $type);
    }

    /** The value is retained; the reason is reported back to the requester. */
    public static function retain(string $reason): self
    {
        return new self(policy: PersonalDataPolicy::Retain, reason: $reason);
    }

    /**
     * The field stores no personal data. The justification is the policy's
     * payload: it is the one policy that cannot be inferred from the data,
     * so the reason a field holds none is part of the declaration.
     */
    public static function notPersonal(string $justification): self
    {
        return new self(policy: PersonalDataPolicy::NotPersonal, reason: $justification);
    }

    public function isExported(): bool
    {
        return PersonalDataPolicy::Export === $this->policy;
    }

    public function isErased(): bool
    {
        return PersonalDataPolicy::Erase === $this->policy;
    }

    public function isAnonymized(): bool
    {
        return PersonalDataPolicy::Anonymize === $this->policy;
    }
}
