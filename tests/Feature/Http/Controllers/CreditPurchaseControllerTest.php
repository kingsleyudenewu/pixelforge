<?php

use App\Enums\CreditTransactionType;
use App\Models\User;

test('buying a credit pack adds it to the balance and the ledger', function () {
    $user = User::factory()->withCredits('1.00')->create();

    $response = $this->actingAs($user)
        ->from(route('dashboard'))
        ->post(route('credits.purchases.store'), ['amount' => '10.00', 'idempotency_key' => '9b2f3c1e-8a7d-4f6b-9c5e-2d1a0b3c4d5e']);

    $response->assertRedirect(route('dashboard'))->assertInertiaFlash('toast.message', 'Added $10.00 in credits.');
    expect($user->refresh()->credit_balance_nanos)->toBe(11_000_000_000);
    $this->assertDatabaseHas('credit_transactions', [
        'user_id' => $user->id,
        'type' => CreditTransactionType::Purchase->value,
        'amount_nanos' => 10_000_000_000,
    ]);
});

test('submitting the same purchase twice adds the credits once', function () {
    $user = User::factory()->create();
    $purchase = ['amount' => '5.00', 'idempotency_key' => '9b2f3c1e-8a7d-4f6b-9c5e-2d1a0b3c4d5e'];

    $this->actingAs($user)->post(route('credits.purchases.store'), $purchase);
    $this->actingAs($user)->post(route('credits.purchases.store'), $purchase);

    expect($user->refresh()->credit_balance_nanos)->toBe(5_000_000_000);
    $this->assertDatabaseCount('credit_transactions', 1);
});

test('only the offered credit packs can be bought', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('credits.purchases.store'), [
        'amount' => '1000000.00',
        'idempotency_key' => '9b2f3c1e-8a7d-4f6b-9c5e-2d1a0b3c4d5e',
    ]);

    $response->assertSessionHasErrors('amount');
    expect($user->refresh()->credit_balance_nanos)->toBe(0);
});
