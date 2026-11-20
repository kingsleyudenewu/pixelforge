<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GenerationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReportRequest;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use App\Support\Nanodollars;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Operator report: what users were charged, what Pixelforge charged us and the profit, per UTC day
 * and per user, with Pixelforge's own usage report alongside to prove the provider costs match.
 */
class ReportController extends Controller
{
    public function index(ReportRequest $request, PixelforgeClient $pixelforge): Response
    {
        $start = $request->startDate();
        $end = $request->endDate();
        $rows = $this->billedTotals($start, $end);

        $users = User::query()
            ->whereIn('id', $rows->pluck('user_id')->filter()->unique())
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        return Inertia::render('admin/Report', [
            'filters' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'totals' => $this->summarise($rows),
            'days' => $rows->groupBy('date')
                ->map(fn (Collection $dayRows, string $date): array => ['date' => $date, ...$this->summarise($dayRows)])
                ->sortKeysDesc()
                ->values(),
            'users' => $rows->groupBy(fn (array $row): string => (string) $row['user_id'])
                ->sortByDesc(fn (Collection $userRows): int => $userRows->sum('charged_nanos'))
                ->map(function (Collection $userRows) use ($users): array {
                    $user = $users->get($userRows->first()['user_id']);

                    return [
                        'user' => $user === null ? null : ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
                        ...$this->summarise($userRows),
                    ];
                })
                ->values(),
            'reconciliation' => Inertia::defer(fn (): array => $this->reconcile($pixelforge, $start, $end, $rows), rescue: true),
        ]);
    }

    /**
     * Totals for succeeded generations, grouped by the UTC day Pixelforge billed them and by user.
     *
     * @return Collection<int, array{date: string, user_id: int|null, generations: int, charged_nanos: int, provider_cost_nanos: int}>
     */
    private function billedTotals(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return Generation::query()
            ->where('status', GenerationStatus::Succeeded)
            ->whereBetween('billed_on', [$start->toDateString(), $end->toDateString()])
            ->groupBy('billed_on', 'user_id')
            ->select(['billed_on', 'user_id'])
            ->selectRaw('COUNT(*) AS generations, SUM(charge_nanos) AS charged_nanos, SUM(provider_cost_nanos) AS provider_cost_nanos')
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'date' => (string) $row->billed_on,
                'user_id' => $row->user_id === null ? null : (int) $row->user_id,
                'generations' => (int) $row->generations,
                'charged_nanos' => (int) $row->charged_nanos,
                'provider_cost_nanos' => (int) $row->provider_cost_nanos,
            ]);
    }

    /**
     * Compare our provider costs with Pixelforge's GET /v1/usage, day by day, to the nanodollar.
     *
     * @param  Collection<int, array{date: string, user_id: int|null, generations: int, charged_nanos: int, provider_cost_nanos: int}>  $rows
     * @return array<int, array{date: string, generations: int, provider_cost: string, pixelforge_generations: int, pixelforge_cost: string, matches: bool}>
     */
    private function reconcile(PixelforgeClient $pixelforge, CarbonImmutable $start, CarbonImmutable $end, Collection $rows): array
    {
        $usage = $pixelforge->dailyUsage($start, $end);
        $local = $rows->groupBy('date');

        return collect(array_keys($usage))
            ->merge($local->keys())
            ->unique()
            ->sortDesc()
            ->map(function (string $date) use ($usage, $local): array {
                $ours = $local->get($date, collect());
                $generations = (int) $ours->sum('generations');
                $providerCost = (int) $ours->sum('provider_cost_nanos');
                $pixelforgeCost = $usage[$date]['cost_nanos'] ?? 0;

                return [
                    'date' => $date,
                    'generations' => $generations,
                    'provider_cost' => Nanodollars::toDecimal($providerCost),
                    'pixelforge_generations' => $usage[$date]['generations'] ?? 0,
                    'pixelforge_cost' => Nanodollars::toDecimal($pixelforgeCost),
                    'matches' => $providerCost === $pixelforgeCost,
                ];
            })
            ->filter(fn (array $day): bool => $day['generations'] > 0 || $day['pixelforge_generations'] > 0 || ! $day['matches'])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array{date: string, user_id: int|null, generations: int, charged_nanos: int, provider_cost_nanos: int}>  $rows
     * @return array{generations: int, charged: string, provider_cost: string, profit: string}
     */
    private function summarise(Collection $rows): array
    {
        $charged = (int) $rows->sum('charged_nanos');
        $providerCost = (int) $rows->sum('provider_cost_nanos');

        return [
            'generations' => (int) $rows->sum('generations'),
            'charged' => Nanodollars::toDecimal($charged),
            'provider_cost' => Nanodollars::toDecimal($providerCost),
            'profit' => Nanodollars::toDecimal($charged - $providerCost),
        ];
    }
}
