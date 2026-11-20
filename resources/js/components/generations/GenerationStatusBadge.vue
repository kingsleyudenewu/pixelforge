<script setup lang="ts">
import { CircleCheck, CircleX, Clock } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import type { GenerationStatus } from '@/types';

const props = defineProps<{
    status: GenerationStatus;
}>();

const presentations: Record<
    GenerationStatus,
    { label: string; classes: string }
> = {
    queued: {
        label: 'Queued',
        classes: 'bg-secondary text-secondary-foreground',
    },
    processing: {
        label: 'Processing',
        classes:
            'bg-blue-100 text-blue-800 dark:bg-blue-500/20 dark:text-blue-200',
    },
    succeeded: {
        label: 'Succeeded',
        classes:
            'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200',
    },
    failed: {
        label: 'Failed',
        classes: 'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-200',
    },
};

const presentation = computed(() => presentations[props.status]);
</script>

<template>
    <Badge
        variant="outline"
        :class="['border-transparent shadow-xs', presentation.classes]"
    >
        <Clock v-if="status === 'queued'" aria-hidden="true" />
        <Spinner
            v-else-if="status === 'processing'"
            class="size-3"
            aria-hidden="true"
        />
        <CircleCheck v-else-if="status === 'succeeded'" aria-hidden="true" />
        <CircleX v-else aria-hidden="true" />
        {{ presentation.label }}
    </Badge>
</template>
