<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What happened when checkout details were matched against existing customers.
 *
 * Spec §1.4 is explicit that a match must never silently link and must never
 * reveal that an account exists. These outcomes are for the server's own
 * bookkeeping and for staff — they are NOT for the customer. Rendering
 * different UI depending on which of these came back would leak exactly the
 * fact the specification forbids disclosing.
 */
enum CustomerResolutionOutcome: string
{
    /** Nothing matched. A genuinely new customer. */
    case Created = 'created';

    /**
     * Details matched an existing customer, but the customer did not sign in
     * or verify, so a fresh unlinked record was created for staff to merge.
     * Spec §1.4 rule 4.
     */
    case CreatedUnlinkedAfterMatch = 'created_unlinked_after_match';

    /**
     * The email matched one customer and the phone matched another. Linked to
     * neither and flagged for review. Spec §1.4 conflict rule.
     */
    case CreatedUnlinkedWithConflict = 'created_unlinked_with_conflict';

    /**
     * Linked to an existing customer, which is only permitted after a
     * successful sign-in or an OTP verification of the email or phone.
     */
    case LinkedExisting = 'linked_existing';

    public function createdNewRecord(): bool
    {
        return $this !== self::LinkedExisting;
    }
}
