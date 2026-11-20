<script setup lang="ts">
import { ImageOff, TriangleAlert } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import GenerationStatusBadge from '@/components/generations/GenerationStatusBadge.vue';
import { Spinner } from '@/components/ui/spinner';
import { formatUsd, formatUsdExact, toNanos } from '@/lib/money';
import type { Generation } from '@/types';

const props = defineProps<{
    generation: Generation;
}>();

// Pixelforge's signed image URLs expire; if one stops loading early, show a placeholder instead.
const imageFailed = ref(false);
watch(
    () => props.generation.image_url,
    () => (imageFailed.value = false),
);

const isInFlight = computed(() =>
    ['queued', 'processing'].includes(props.generation.status),
);
const showImage = computed(
    () => props.generation.image_url !== null && !imageFailed.value,
);

const shape = computed(() => {
    const { width, height } = props.generation;

    return width === height
        ? 'Square'
        : width > height
          ? 'Landscape'
          : 'Portrait';
});

const estimate = computed(
    () =>
        `${formatUsd(toNanos(props.generation.estimated_charge_min), { rounding: 'down' })} – ${formatUsd(
            toNanos(props.generation.estimated_charge_max),
            { rounding: 'up' },
        )}`,
);

const actual = computed(() => {
    if (props.generation.status === 'failed') {
        return 'Free (failed)';
    }

    return props.generation.charge === null
        ? 'When finished'
        : formatUsd(toNanos(props.generation.charge));
});

const createdAt = computed(() =>
    props.generation.created_at
        ? new Intl.DateTimeFormat(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(props.generation.created_at))
        : '',
);
</script>

<template>
    <article
        class="flex flex-col overflow-hidden rounded-xl border bg-card text-card-foreground shadow-xs"
    >
        <div
            class="relative overflow-hidden bg-muted"
            :style="{
                aspectRatio: `${generation.width} / ${generation.height}`,
            }"
        >
            <a
                v-if="showImage"
                :href="generation.image_url ?? undefined"
                target="_blank"
                rel="noopener noreferrer"
                class="block size-full"
            >
                <img
                    :src="generation.image_url ?? undefined"
                    :alt="generation.prompt"
                    :width="generation.width"
                    :height="generation.height"
                    loading="lazy"
                    decoding="async"
                    class="size-full object-cover"
                    @error="imageFailed = true"
                />
            </a>

            <div
                v-else
                class="absolute inset-0 flex flex-col items-center justify-center gap-2 p-4 text-center text-sm text-muted-foreground"
            >
                <template v-if="isInFlight">
                    <div
                        class="absolute inset-0 animate-pulse bg-muted-foreground/10"
                        aria-hidden="true"
                    />
                    <Spinner class="size-6" />
                    <span>{{
                        generation.status === 'queued'
                            ? 'Waiting in the queue…'
                            : 'Generating your image…'
                    }}</span>
                </template>
                <template v-else-if="generation.status === 'failed'">
                    <TriangleAlert
                        class="size-6 text-destructive"
                        aria-hidden="true"
                    />
                    <span class="line-clamp-4">{{
                        generation.error_message
                    }}</span>
                </template>
                <template v-else>
                    <ImageOff class="size-6" aria-hidden="true" />
                    <span>The image link has expired.</span>
                </template>
            </div>

            <GenerationStatusBadge
                :status="generation.status"
                class="absolute top-2 left-2"
            />
        </div>

        <div class="flex flex-1 flex-col gap-3 p-4">
            <p class="line-clamp-2 text-sm" :title="generation.prompt">
                {{ generation.prompt }}
            </p>
            <p class="text-xs text-muted-foreground">
                {{ shape }} {{ generation.width }}×{{ generation.height }} ·
                {{ generation.quality }} quality
                <template v-if="generation.reference_image_count > 0">
                    · {{ generation.reference_image_count }}
                    {{
                        generation.reference_image_count === 1
                            ? 'reference'
                            : 'references'
                    }}
                </template>
                <template v-if="createdAt"> · {{ createdAt }}</template>
            </p>

            <dl class="mt-auto grid grid-cols-2 gap-3 border-t pt-3 text-xs">
                <div class="space-y-0.5">
                    <dt class="text-muted-foreground">Estimated</dt>
                    <dd class="font-medium tabular-nums">{{ estimate }}</dd>
                </div>
                <div class="space-y-0.5">
                    <dt class="text-muted-foreground">Actual cost</dt>
                    <dd
                        class="font-medium tabular-nums"
                        :title="
                            generation.charge !== null
                                ? formatUsdExact(generation.charge)
                                : undefined
                        "
                    >
                        {{ actual }}
                    </dd>
                </div>
            </dl>
        </div>
    </article>
</template>
