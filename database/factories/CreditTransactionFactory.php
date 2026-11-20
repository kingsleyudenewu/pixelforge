<?php

namespace Database\Factories;

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CreditTransaction>
 */
class CreditTransactionFactory extends Factory
{
    /**
     * Define the model's default state: a $10 credit purchase.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => CreditTransactionType::Purchase,
            'amount_nanos' => 10_000_000_000,
            'idempotency_key' => (string) Str::uuid(),
        ];
    }
}
