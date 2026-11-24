<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Consent;

/** The outcome of one {@see ConsentEvaluator} check, with the reason for it. */
final class Decision
{
    /**
     * @param LegalBasis|null $legalBasis the basis the vendor would process under, once one could be
     *                                    determined; null when the check failed before that point
     */
    public function __construct(
        public readonly bool $allowed,
        public readonly DecisionReason $reason,
        public readonly ?LegalBasis $legalBasis = null,
    ) {
    }

    public static function allow(LegalBasis $legalBasis): self
    {
        return new self(true, DecisionReason::ALLOWED, $legalBasis);
    }

    public static function deny(DecisionReason $reason, ?LegalBasis $legalBasis = null): self
    {
        return new self(false, $reason, $legalBasis);
    }
}
