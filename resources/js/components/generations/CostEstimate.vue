<script setup lang="ts">
import { ChevronDown, Wallet } from '@lucide/vue';
import { computed } from 'vue';
import BuyCreditsDialog from '@/components/credits/BuyCreditsDialog.vue';
import { Button } from '@/components/ui/button';
import { formatUsd } from '@/lib/money';
import type { PriceEstimate } from '@/lib/pricing';

const props = defineProps<{
    estimate: PriceEstimate;
    balance: number;
    markupBasisPoints: number;
}>();

const tokens = new Intl.NumberFormat('en-US');

const range = (min: number, max: number) =>
    min === max
        ? tokens.format(min)
        : `${tokens.format(min)}–${tokens.format(max)}`;

const canAfford = computed(() => props.estimate.maxCharge <= props.balance);
const markupPercent = computed(() => props.markupBasisPoints / 100);
</script>

<template>
    <section aria-labelledby="cost-estimate-heading" class="space-y-4">
        <div class="space-y-1">
            <h2
                id="cost-estimate-heading"
                class="text-sm font-medium text-muted-foreground"
            >
                Estimated cost
            </h2>
            <p
                class="text-2xl font-semibold tracking-tight tabular-nums"
                aria-live="polite"
            >
                {{ formatUsd(estimate.minCharge, { rounding: 'down' }) }} –
                {{ formatUsd(estimate.maxCharge, { rounding: 'up' }) }}
            </p>
            <p class="text-sm text-muted-foreground">
                You're charged the actual cost when the image is ready, never
                more than
                <span class="font-medium text-foreground tabular-nums">{{
                    formatUsd(estimate.maxCharge, { rounding: 'up' })
                }}</span
                >. Failed generations are free.
            </p>
        </div>

        <details class="group rounded-lg border text-sm">
            <summary
                class="flex cursor-pointer list-none items-center justify-between px-3 py-2 font-medium"
            >
                How this is calculated
                <ChevronDown
                    class="size-4 transition-transform group-open:rotate-180"
                    aria-hidden="true"
                />
            </summary>
            <dl
                class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1.5 border-t px-3 py-3 text-muted-foreground"
            >
                <dt>Prompt text tokens</dt>
                <dd class="text-right tabular-nums">
                    {{ range(estimate.minTextTokens, estimate.maxTextTokens) }}
                </dd>
                <dt>Reference image tokens</dt>
                <dd class="text-right tabular-nums">
                    {{ tokens.format(estimate.imageTokens) }}
                </dd>
                <dt>Output tokens</dt>
                <dd class="text-right tabular-nums">
                    {{ tokens.format(estimate.outputTokens) }}
                </dd>
                <dt>Reasoning tokens</dt>
                <dd class="text-right tabular-nums">
                    {{
                        range(
                            estimate.minReasoningTokens,
                            estimate.maxReasoningTokens,
                        )
                    }}
                </dd>
                <dt class="border-t pt-1.5">Pixelforge cost</dt>
                <dd class="border-t pt-1.5 text-right tabular-nums">
                    {{
                        formatUsd(estimate.minProviderCost, {
                            decimals: 6,
                            rounding: 'down',
                        })
                    }}
                    –
                    {{
                        formatUsd(estimate.maxProviderCost, {
                            decimals: 6,
                            rounding: 'up',
                        })
                    }}
                </dd>
                <dt>Service fee</dt>
                <dd class="text-right tabular-nums">+{{ markupPercent }}%</dd>
            </dl>
            <p class="border-t px-3 py-2 text-xs text-muted-foreground">
                Text and reasoning tokens are only known once the image is
                finished, which is why the price is a range.
            </p>
        </details>

        <div
            class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm"
            :class="
                canAfford
                    ? 'bg-muted'
                    : 'bg-red-50 text-red-800 dark:bg-red-500/10 dark:text-red-200'
            "
        >
            <div class="flex items-center gap-2">
                <Wallet class="size-4 shrink-0" aria-hidden="true" />
                <span v-if="canAfford">
                    Balance
                    {{ formatUsd(balance, { decimals: 2, rounding: 'down' }) }},
                    at least
                    {{
                        formatUsd(balance - estimate.maxCharge, {
                            decimals: 2,
                            rounding: 'down',
                        })
                    }}
                    after this
                </span>
                <span v-else>
                    Your balance of
                    {{ formatUsd(balance, { rounding: 'down' }) }} can't cover
                    the maximum of
                    {{ formatUsd(estimate.maxCharge, { rounding: 'up' }) }}.
                </span>
            </div>
            <BuyCreditsDialog>
                <Button size="sm" :variant="canAfford ? 'ghost' : 'default'"
                    >Buy credits</Button
                >
            </BuyCreditsDialog>
        </div>
    </section>
</template>
