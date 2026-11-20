<?php

use App\Enums\CreditTransactionType;
use App\Models\User;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('new users start with the welcome credit recorded in their ledger', function () {
    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::firstWhere('email', 'test@example.com');
    expect($user->credit_balance_nanos)->toBe(1_000_000_000);
    $this->assertDatabaseHas('credit_transactions', [
        'user_id' => $user->id,
        'type' => CreditTransactionType::Grant->value,
        'amount_nanos' => 1_000_000_000,
    ]);
});
