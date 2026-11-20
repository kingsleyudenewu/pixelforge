<?php

namespace App\Http\Controllers;

use App\Http\Requests\PurchaseCreditsRequest;
use App\Services\Billing\CreditLedger;
use App\Support\Nanodollars;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CreditPurchaseController extends Controller
{
    /**
     * Add a credit pack to the user's balance. A fake purchase: no payment is taken.
     */
    public function store(PurchaseCreditsRequest $request, CreditLedger $ledger): RedirectResponse
    {
        $amount = Nanodollars::fromDecimal($request->string('amount')->toString());

        $purchased = DB::transaction(fn (): bool => $ledger->purchase(
            $request->user(),
            $amount,
            $request->string('idempotency_key')->toString(),
        ));

        if ($purchased) {
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Added '.Nanodollars::formatUsd($amount, 2).' in credits.']);
        }

        return back();
    }
}
