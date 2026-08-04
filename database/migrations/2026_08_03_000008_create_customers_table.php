<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * People who hire vehicles.
 *
 * NOTE THE ABSENCE OF UNIQUE CONSTRAINTS on email and phone. That is not an
 * oversight. Spec §1.4 requires that when a checkout's email or phone matches an
 * existing customer, the system must NOT silently link to it and must NOT reveal
 * that an account exists — it creates a new, unlinked record for staff to merge
 * later. A unique constraint would make the specified behaviour impossible.
 *
 * Duplicates are therefore expected. Merging them is a deliberate staff action,
 * not something the database prevents.
 *
 * `phone_e164` is the normalised form and the ONLY column that may be used for
 * matching. `phone_raw` keeps whatever the customer actually typed, because when
 * a normalisation looks wrong, staff need to see the original.
 *
 * `password` is nullable — guest records have none, and an invitation to set one
 * is sent after booking, never before. (The guideline calls this column
 * `password_hash`; using Laravel's conventional name avoids having to override
 * the auth contract when customer sign-in arrives.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('full_name');
            $table->string('email');

            // E.164, e.g. +260977123456. Normalised on write AND before any
            // lookup — normalising only on save, then querying with raw input,
            // fails silently and breeds duplicates.
            //
            // Nullable on purpose: if a number cannot be parsed, this stays
            // null rather than holding something that merely looks canonical.
            // A junk value here would match every other junk value and link
            // unrelated people together.
            $table->string('phone_e164')->nullable();
            $table->string('phone_raw')->nullable();
            $table->string('phone_region', 2)->nullable();

            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();

            $table->string('password')->nullable();
            $table->rememberToken();

            // Set ONLY for the genuine ambiguity of spec §1.4's conflict rule:
            // the email matched one customer while the phone matched a
            // different one, so we linked to neither. This is a small, and
            // therefore actionable, queue.
            $table->boolean('needs_staff_review')->default(false);
            $table->text('review_reason')->nullable();

            // A returning guest who chose not to sign in gets a fresh record,
            // as the specification requires. That is an ordinary duplicate, not
            // an ambiguity, so it is recorded here rather than in the review
            // queue — otherwise every repeat customer would drown the queue and
            // staff would stop reading it.
            $table->unsignedBigInteger('possible_duplicate_of_customer_id')->nullable();

            $table->timestamps();

            // Indexes, not unique constraints — see the note above.
            $table->index('email');
            $table->index('phone_e164');
            $table->index('needs_staff_review');
            $table->index('possible_duplicate_of_customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
