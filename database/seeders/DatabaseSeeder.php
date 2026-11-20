<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Billing\CreditLedger;
use App\Support\Nanodollars;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(CreditLedger $ledger): void
    {
        $admin = User::factory()->admin()->create([
            'name' => 'Operator',
            'email' => 'admin@example.com',
        ]);

        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // Fund through the ledger so every balance equals the sum of its transactions.
        $ledger->grant($admin, Nanodollars::fromDecimal('25.00'));
        $ledger->grant($user, Nanodollars::fromDecimal('10.00'));
    }
}
