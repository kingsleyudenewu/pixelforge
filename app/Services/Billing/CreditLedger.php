<?php

namespace App\Services\Billing;

use App\Enums\CreditTransactionType;
use App\Exceptions\InsufficientCreditsException;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;

/**
 * Moves credits (US dollars, held in nanodollars) and records every movement in an append-only ledger.
 *
 * Every method must run inside the caller's database transaction so the balance and the ledger
 * change together. Balances change with single atomic UPDATE statements, never read-modify-write.
 * The ledger's unique (generation_id, type) index rejects a second reservation, release or charge
 * for the same generation, even if some other guard has failed.
 */
class CreditLedger
{
    /**
     * Hold a generation's quoted maximum from the user's balance.
     *
     * @throws InsufficientCreditsException
     */
    public function reserve(User $user, Generation $generation, int $amountNanos): void
    {
        $reserved = User::query()
            ->whereKey($user->id)
            ->where('credit_balance_nanos', '>=', $amountNanos)
            ->decrement('credit_balance_nanos', $amountNanos);

        if ($reserved === 0) {
            throw new InsufficientCreditsException('The user cannot afford this generation.');
        }

        $this->record($user->id, CreditTransactionType::Reservation, -$amountNanos, $generation->id);
    }

    /**
     * Return a succeeded generation's hold and charge what it actually cost.
     */
    public function settle(Generation $generation, int $chargeNanos): void
    {
        if ($generation->user_id === null) {
            return;
        }

        $hold = $generation->estimated_charge_max_nanos;

        User::query()->whereKey($generation->user_id)->increment('credit_balance_nanos', $hold - $chargeNanos);

        $this->record($generation->user_id, CreditTransactionType::ReservationRelease, $hold, $generation->id);
        $this->record($generation->user_id, CreditTransactionType::Charge, -$chargeNanos, $generation->id);
    }

    /**
     * Return a failed generation's hold in full: failures cost nothing.
     */
    public function release(Generation $generation): void
    {
        if ($generation->user_id === null) {
            return;
        }

        $hold = $generation->estimated_charge_max_nanos;

        User::query()->whereKey($generation->user_id)->increment('credit_balance_nanos', $hold);

        $this->record($generation->user_id, CreditTransactionType::ReservationRelease, $hold, $generation->id);
    }

    /**
     * Charge a generation whose hold was already released, for when Pixelforge reports success
     * after we had given up on it. The balance may go below zero.
     */
    public function chargeWithoutHold(Generation $generation, int $chargeNanos): void
    {
        if ($generation->user_id === null) {
            return;
        }

        User::query()->whereKey($generation->user_id)->decrement('credit_balance_nanos', $chargeNanos);

        $this->record($generation->user_id, CreditTransactionType::Charge, -$chargeNanos, $generation->id);
    }

    /**
     * Add bought credits. Returns false (and changes nothing) if this purchase was already recorded.
     */
    public function purchase(User $user, int $amountNanos, string $idempotencyKey): bool
    {
        $inserted = CreditTransaction::query()->insertOrIgnore([
            'user_id' => $user->id,
            'type' => CreditTransactionType::Purchase->value,
            'amount_nanos' => $amountNanos,
            'idempotency_key' => $idempotencyKey,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 0) {
            return false;
        }

        User::query()->whereKey($user->id)->increment('credit_balance_nanos', $amountNanos);

        return true;
    }

    /**
     * Give the user free credits, such as the welcome credit.
     */
    public function grant(User $user, int $amountNanos): void
    {
        User::query()->whereKey($user->id)->increment('credit_balance_nanos', $amountNanos);

        $this->record($user->id, CreditTransactionType::Grant, $amountNanos);
    }

    private function record(int $userId, CreditTransactionType $type, int $amountNanos, ?string $generationId = null): void
    {
        CreditTransaction::create([
            'user_id' => $userId,
            'generation_id' => $generationId,
            'type' => $type,
            'amount_nanos' => $amountNanos,
        ]);
    }
}
