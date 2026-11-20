<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Services\Billing\CreditLedger;
use App\Support\Nanodollars;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private readonly CreditLedger $ledger) {}

    /**
     * Validate and create a newly registered user, with their welcome credit.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        return DB::transaction(function () use ($input): User {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            $welcomeCredit = Nanodollars::fromDecimal((string) config('billing.signup_credit'));

            if ($welcomeCredit > 0) {
                $this->ledger->grant($user, $welcomeCredit);
            }

            return $user;
        });
    }
}
