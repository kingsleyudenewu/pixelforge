<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { store } from '@/actions/App/Http/Controllers/CreditPurchaseController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { formatUsd, toNanos } from '@/lib/money';
import { uuid } from '@/lib/uuid';

const page = usePage();
const open = ref(false);
const purchasing = ref<string | null>(null);
const error = ref<string | undefined>();

const packs = computed(() => page.props.credits?.packs ?? []);
const balance = computed(() => toNanos(page.props.credits?.balance ?? '0'));

// One key per opening of the dialog: a double click can't add the same credits twice.
let idempotencyKey = uuid();

watch(open, (isOpen) => {
    if (isOpen) {
        idempotencyKey = uuid();
        error.value = undefined;
    }
});

function buy(amount: string) {
    router.post(
        store.url(),
        { amount, idempotency_key: idempotencyKey },
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => (purchasing.value = amount),
            onFinish: () => (purchasing.value = null),
            onSuccess: () => (open.value = false),
            onError: (errors) =>
                (error.value = errors.amount ?? errors.idempotency_key),
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <slot />
        </DialogTrigger>
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Buy credits</DialogTitle>
                <DialogDescription>
                    Credits are US dollars. Your balance is
                    {{ formatUsd(balance, { decimals: 2, rounding: 'down' }) }}.
                    This is a demo, so no payment is taken.
                </DialogDescription>
            </DialogHeader>

            <div class="grid grid-cols-2 gap-3">
                <Button
                    v-for="pack in packs"
                    :key="pack"
                    variant="outline"
                    class="h-16 text-lg font-semibold tabular-nums"
                    :disabled="purchasing !== null"
                    @click="buy(pack)"
                >
                    <Spinner v-if="purchasing === pack" />
                    {{ formatUsd(toNanos(pack), { decimals: 2 }) }}
                </Button>
            </div>

            <InputError :message="error" />
        </DialogContent>
    </Dialog>
</template>
