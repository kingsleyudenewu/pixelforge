<script setup lang="ts">
import { Deferred, Head, router, useForm } from '@inertiajs/vue3';
import { CircleCheck, RefreshCw, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { formatUsdExact, toNanos } from '@/lib/money';
import { index as adminReports } from '@/routes/admin/reports';

type Totals = {
    generations: number;
    charged: string;
    provider_cost: string;
    profit: string;
};

type ReconciledDay = {
    date: string;
    generations: number;
    provider_cost: string;
    pixelforge_generations: number;
    pixelforge_cost: string;
    matches: boolean;
};

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Admin report',
                href: adminReports(),
            },
        ],
    },
});

const props = defineProps<{
    filters: { start: string; end: string };
    totals: Totals;
    days: (Totals & { date: string })[];
    users: (Totals & {
        user: { id: number; name: string; email: string } | null;
    })[];
    reconciliation?: ReconciledDay[];
}>();

const filters = useForm({ ...props.filters });

function applyFilters() {
    filters.get(adminReports.url(), { preserveScroll: true });
}

const margin = computed(() => {
    const charged = toNanos(props.totals.charged);

    return charged === 0
        ? '—'
        : `${((toNanos(props.totals.profit) / charged) * 100).toFixed(1)}%`;
});

const mismatchedDays = computed(
    () => props.reconciliation?.filter((day) => !day.matches).length ?? 0,
);

// Report dates are UTC days, as Pixelforge bills them.
const dayFormat = new Intl.DateTimeFormat(undefined, {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    timeZone: 'UTC',
});
const formatDay = (date: string) =>
    dayFormat.format(new Date(`${date}T00:00:00Z`));

const kpis = computed(() => [
    {
        label: 'Credit spend',
        hint: 'What users were charged',
        value: formatUsdExact(props.totals.charged),
    },
    {
        label: 'Provider cost',
        hint: 'What Pixelforge charged us',
        value: formatUsdExact(props.totals.provider_cost),
    },
    {
        label: 'Profit',
        hint: `Margin ${margin.value}`,
        value: formatUsdExact(props.totals.profit),
    },
    {
        label: 'Generations',
        hint: 'Succeeded and billed',
        value: props.totals.generations.toLocaleString(),
    },
]);
</script>

<template>
    <Head title="Admin report" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <Heading
                title="Credit spend, provider cost and profit"
                description="Succeeded generations, grouped by the UTC day Pixelforge billed them."
            />

            <form
                class="flex flex-wrap items-end gap-3"
                @submit.prevent="applyFilters"
            >
                <div class="grid gap-1.5">
                    <Label for="start">From</Label>
                    <Input
                        id="start"
                        v-model="filters.start"
                        type="date"
                        class="w-40"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label for="end">To</Label>
                    <Input
                        id="end"
                        v-model="filters.end"
                        type="date"
                        class="w-40"
                    />
                </div>
                <Button
                    type="submit"
                    variant="outline"
                    :disabled="filters.processing"
                    >Apply</Button
                >
            </form>
        </div>
        <InputError :message="filters.errors.start ?? filters.errors.end" />

        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div
                v-for="kpi in kpis"
                :key="kpi.label"
                class="rounded-xl border bg-card p-4 shadow-xs"
            >
                <dt class="text-sm text-muted-foreground">{{ kpi.label }}</dt>
                <dd
                    class="mt-1 text-2xl font-semibold tracking-tight tabular-nums"
                >
                    {{ kpi.value }}
                </dd>
                <dd class="mt-1 text-xs text-muted-foreground">
                    {{ kpi.hint }}
                </dd>
            </div>
        </dl>

        <section
            aria-labelledby="reconciliation-heading"
            class="space-y-3 rounded-xl border bg-card p-4 shadow-xs"
        >
            <div class="space-y-1">
                <h2 id="reconciliation-heading" class="font-semibold">
                    Pixelforge reconciliation
                </h2>
                <p class="text-sm text-muted-foreground">
                    Our recorded provider cost for each day, checked to the
                    nanodollar against Pixelforge's
                    <code class="text-xs">GET /v1/usage</code>. Generations
                    finished in the last minute may still be syncing.
                </p>
            </div>

            <Deferred data="reconciliation">
                <template #fallback>
                    <div
                        class="space-y-2"
                        aria-label="Loading Pixelforge usage"
                    >
                        <Skeleton
                            v-for="row in 4"
                            :key="row"
                            class="h-8 w-full"
                        />
                    </div>
                </template>

                <template #rescue="{ reloading }">
                    <Alert variant="destructive">
                        <TriangleAlert />
                        <AlertTitle
                            >Couldn't load Pixelforge's usage report</AlertTitle
                        >
                        <AlertDescription
                            class="flex flex-wrap items-center gap-3"
                        >
                            Pixelforge didn't respond. Our own figures above are
                            unaffected.
                            <Button
                                size="sm"
                                variant="outline"
                                :disabled="reloading"
                                @click="
                                    router.reload({ only: ['reconciliation'] })
                                "
                            >
                                <RefreshCw />
                                Retry
                            </Button>
                        </AlertDescription>
                    </Alert>
                </template>

                <p
                    class="flex items-center gap-2 text-sm font-medium"
                    :class="
                        mismatchedDays === 0
                            ? 'text-emerald-700 dark:text-emerald-300'
                            : 'text-amber-700 dark:text-amber-300'
                    "
                >
                    <CircleCheck
                        v-if="mismatchedDays === 0"
                        class="size-4"
                        aria-hidden="true"
                    />
                    <TriangleAlert v-else class="size-4" aria-hidden="true" />
                    <template v-if="mismatchedDays === 0">
                        Every day matches Pixelforge ({{
                            reconciliation?.length ?? 0
                        }}
                        with activity).
                    </template>
                    <template v-else>
                        {{ mismatchedDays }}
                        {{
                            mismatchedDays === 1 ? 'day differs' : 'days differ'
                        }}
                        from Pixelforge.
                    </template>
                </p>

                <div v-if="reconciliation?.length" class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-muted-foreground">
                            <tr class="border-b">
                                <th class="py-2 pr-4 font-medium">Day (UTC)</th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    Our provider cost
                                </th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    Pixelforge cost
                                </th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    Our count
                                </th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    Pixelforge count
                                </th>
                                <th class="py-2 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="day in reconciliation"
                                :key="day.date"
                                class="border-b last:border-0"
                            >
                                <td class="py-2 pr-4 whitespace-nowrap">
                                    {{ formatDay(day.date) }}
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{ formatUsdExact(day.provider_cost) }}
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{ formatUsdExact(day.pixelforge_cost) }}
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{ day.generations }}
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{ day.pixelforge_generations }}
                                </td>
                                <td class="py-2">
                                    <span
                                        v-if="day.matches"
                                        class="inline-flex items-center gap-1 text-emerald-700 dark:text-emerald-300"
                                    >
                                        <CircleCheck
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        Matches
                                    </span>
                                    <span
                                        v-else
                                        class="inline-flex items-center gap-1 text-amber-700 dark:text-amber-300"
                                    >
                                        <TriangleAlert
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        Differs
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Deferred>
        </section>

        <div class="grid gap-6 xl:grid-cols-2">
            <section
                aria-labelledby="daily-heading"
                class="space-y-3 rounded-xl border bg-card p-4 shadow-xs"
            >
                <h2 id="daily-heading" class="font-semibold">By day</h2>
                <div v-if="days.length" class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-muted-foreground">
                            <tr class="border-b">
                                <th class="py-2 pr-4 font-medium">Day (UTC)</th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    Generations
                                </th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    Credit spend
                                </th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    Provider cost
                                </th>
                                <th class="py-2 text-right font-medium">
                                    Profit
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="day in days"
                                :key="day.date"
                                class="border-b last:border-0"
                            >
                                <td class="py-2 pr-4 whitespace-nowrap">
                                    {{ formatDay(day.date) }}
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{ day.generations }}
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{ formatUsdExact(day.charged) }}
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{ formatUsdExact(day.provider_cost) }}
                                </td>
                                <td
                                    class="py-2 text-right font-medium tabular-nums"
                                >
                                    {{ formatUsdExact(day.profit) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p
                    v-else
                    class="py-6 text-center text-sm text-muted-foreground"
                >
                    No billed generations in this range.
                </p>
            </section>

            <section
                aria-labelledby="users-heading"
                class="space-y-3 rounded-xl border bg-card p-4 shadow-xs"
            >
                <h2 id="users-heading" class="font-semibold">By user</h2>
                <div v-if="users.length" class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-muted-foreground">
                            <tr class="border-b">
                                <th class="py-2 pr-4 font-medium">User</th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    Generations
                                </th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    Credit spend
                                </th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    Provider cost
                                </th>
                                <th class="py-2 text-right font-medium">
                                    Profit
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in users"
                                :key="row.user?.id ?? 'deleted'"
                                class="border-b last:border-0"
                            >
                                <td class="py-2 pr-4">
                                    <template v-if="row.user">
                                        <div class="font-medium">
                                            {{ row.user.name }}
                                        </div>
                                        <div
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ row.user.email }}
                                        </div>
                                    </template>
                                    <span
                                        v-else
                                        class="text-muted-foreground italic"
                                        >Deleted account</span
                                    >
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{ row.generations }}
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{ formatUsdExact(row.charged) }}
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{ formatUsdExact(row.provider_cost) }}
                                </td>
                                <td
                                    class="py-2 text-right font-medium tabular-nums"
                                >
                                    {{ formatUsdExact(row.profit) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p
                    v-else
                    class="py-6 text-center text-sm text-muted-foreground"
                >
                    No billed generations in this range.
                </p>
            </section>
        </div>
    </div>
</template>
