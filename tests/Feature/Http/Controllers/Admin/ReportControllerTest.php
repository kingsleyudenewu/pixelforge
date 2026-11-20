<?php

use App\Models\Generation;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Two users' billed generations in November 2026, plus generations the report must ignore.
 * Alice: two generations on 18 Nov at $0.05655 cost / $0.0706875 charged each.
 * Bob: one generation on 19 Nov at $0.06281 cost / $0.0785125 charged.
 *
 * @return array{alice: User, bob: User}
 */
function billedNovember(): array
{
    $alice = User::factory()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
    $bob = User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.com']);

    Generation::factory()->count(2)->for($alice)->succeeded()->create(['billed_on' => '2026-11-18']);
    Generation::factory()->for($bob)->succeeded()->create([
        'billed_on' => '2026-11-19',
        'provider_cost_nanos' => 62_810_000,
        'charge_nanos' => 78_512_500,
    ]);

    Generation::factory()->for($bob)->failed()->create();
    Generation::factory()->for($bob)->processing()->create();
    Generation::factory()->for($alice)->succeeded()->create(['billed_on' => '2026-10-01']);

    return ['alice' => $alice, 'bob' => $bob];
}

/**
 * @param  array<string, array{generations: int, cost_usd: string}>  $days
 */
function fakePixelforgeUsage(array $days): void
{
    Http::fake(['pixelforge.promptedit.dev/v1/usage*' => Http::response([
        'object' => 'usage',
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-30',
        'data' => collect($days)->map(fn (array $day, string $date) => [
            'date' => $date,
            'generations' => $day['generations'],
            'input_text_tokens' => 0,
            'input_image_tokens' => 0,
            'output_tokens' => 0,
            'reasoning_tokens' => 0,
            'cost_usd' => $day['cost_usd'],
        ])->values()->all(),
    ])]);
}

test('forbids users who are not operators', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.reports.index'))
        ->assertForbidden();
});

test('reports credit spend, provider cost and profit per day and per user', function () {
    billedNovember();

    $response = $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.reports.index', ['start' => '2026-11-01', 'end' => '2026-11-30']));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/Report')
        ->where('totals', ['generations' => 3, 'charged' => '0.219887500', 'provider_cost' => '0.175910000', 'profit' => '0.043977500'])
        ->where('days', [
            ['date' => '2026-11-19', 'generations' => 1, 'charged' => '0.078512500', 'provider_cost' => '0.062810000', 'profit' => '0.015702500'],
            ['date' => '2026-11-18', 'generations' => 2, 'charged' => '0.141375000', 'provider_cost' => '0.113100000', 'profit' => '0.028275000'],
        ])
        ->has('users', 2)
        ->where('users.0.user.name', 'Alice')
        ->where('users.0.charged', '0.141375000')
        ->where('users.1.user.name', 'Bob')
        ->where('users.1.profit', '0.015702500')
        ->missing('reconciliation'));
});

test('reconciles each day\'s provider cost against Pixelforge usage', function () {
    billedNovember();
    fakePixelforgeUsage([
        '2026-11-18' => ['generations' => 2, 'cost_usd' => '0.113100000'],
        '2026-11-19' => ['generations' => 1, 'cost_usd' => '0.060000000'],
        '2026-11-20' => ['generations' => 0, 'cost_usd' => '0.000000000'],
    ]);

    $response = $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.reports.index', ['start' => '2026-11-01', 'end' => '2026-11-30']));

    $response->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps(fn (Assert $reload) => $reload
            ->has('reconciliation', 2)
            ->where('reconciliation.0', [
                'date' => '2026-11-19',
                'generations' => 1,
                'provider_cost' => '0.062810000',
                'pixelforge_generations' => 1,
                'pixelforge_cost' => '0.060000000',
                'matches' => false,
            ])
            ->where('reconciliation.1.date', '2026-11-18')
            ->where('reconciliation.1.matches', true)));
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/v1/usage')
        && $request['start_date'] === '2026-11-01'
        && $request['end_date'] === '2026-11-30');
});

test('rejects ranges longer than Pixelforge\'s 92 day usage limit', function () {
    $response = $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.reports.index', ['start' => '2026-01-01', 'end' => '2026-06-30']));

    $response->assertSessionHasErrors(['end' => 'Choose a range of at most 92 days.']);
});
