<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ImagePlus, Sparkles, TriangleAlert, X } from '@lucide/vue';
import { computed } from 'vue';
import { store } from '@/actions/App/Http/Controllers/GenerationController';
import CostEstimate from '@/components/generations/CostEstimate.vue';
import GenerationCard from '@/components/generations/GenerationCard.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useLiveGenerations } from '@/composables/useLiveGenerations';
import { formatUsd, toNanos } from '@/lib/money';
import { characterCount, estimateGeneration } from '@/lib/pricing';
import { dashboard } from '@/routes';
import { index as history } from '@/routes/generations';
import type { Generation, Pricing, QualityOption, SizeOption } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Generate',
                href: dashboard(),
            },
        ],
    },
});

const props = defineProps<{
    pricing: Pricing | null;
    markupBasisPoints: number;
    sizes: SizeOption[];
    qualities: QualityOption[];
    generations: Generation[];
}>();

const MAX_PROMPT_CHARACTERS = 4000;
const MAX_REFERENCE_IMAGES = 4;
// Kept out of the template: vue-tsc mis-scopes a v-for when a literal "https://" sits in a child's attribute.
const REFERENCE_IMAGE_PLACEHOLDER = 'https://example.com/reference.png';

const page = usePage();

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [] as string[],
});

const promptCharacters = computed(() => characterCount(form.prompt));
const referenceImages = computed(() =>
    form.reference_images.map((url) => url.trim()).filter((url) => url !== ''),
);
const balance = computed(() => toNanos(page.props.credits?.balance ?? '0'));

const estimateFor = (size: string, quality: string) =>
    props.pricing
        ? estimateGeneration(props.pricing, props.markupBasisPoints, {
              prompt: form.prompt,
              size,
              quality,
              referenceImageCount: referenceImages.value.length,
          })
        : null;

const estimate = computed(() => estimateFor(form.size, form.quality));

// The most each quality could cost for the current prompt and size, e.g. { high: '$0.4674' }.
const maxPriceByQuality = computed(() =>
    Object.fromEntries(
        props.qualities.map((quality) => {
            const qualityEstimate = estimateFor(form.size, quality.value);

            return [
                quality.value,
                qualityEstimate
                    ? formatUsd(qualityEstimate.maxCharge, { rounding: 'up' })
                    : null,
            ];
        }),
    ),
);
const canAfford = computed(
    () => estimate.value !== null && estimate.value.maxCharge <= balance.value,
);
const canSubmit = computed(
    () =>
        canAfford.value &&
        form.prompt.trim() !== '' &&
        promptCharacters.value <= MAX_PROMPT_CHARACTERS &&
        !form.processing,
);

// Server-side errors that aren't tied to one field (credits, limits, pricing) or are nested.
const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

function addReferenceImage() {
    if (form.reference_images.length < MAX_REFERENCE_IMAGES) {
        form.reference_images.push('');
    }
}

function removeReferenceImage(index: number) {
    form.reference_images.splice(index, 1);
    form.clearErrors();
}

function submit() {
    form.transform((data) => ({
        ...data,
        reference_images: referenceImages.value,
    })).submit(store(), {
        preserveScroll: true,
    });
}

useLiveGenerations(() => props.generations, ['generations']);
</script>

<template>
    <Head title="Generate" />

    <div class="flex flex-1 flex-col gap-8 p-4">
        <div class="grid gap-6 lg:grid-cols-5">
            <form
                class="space-y-6 rounded-xl border bg-card p-6 shadow-xs lg:col-span-3"
                @submit.prevent="submit"
            >
                <div class="space-y-1">
                    <h1 class="text-xl font-semibold tracking-tight">
                        Generate an image
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        Describe what you want to see. You'll see the price
                        before anything is charged.
                    </p>
                </div>

                <div class="grid gap-2">
                    <div class="flex items-baseline justify-between">
                        <Label for="prompt">Prompt</Label>
                        <span
                            class="text-xs tabular-nums"
                            :class="
                                promptCharacters > MAX_PROMPT_CHARACTERS
                                    ? 'text-destructive'
                                    : 'text-muted-foreground'
                            "
                        >
                            {{ promptCharacters.toLocaleString() }} /
                            {{ MAX_PROMPT_CHARACTERS.toLocaleString() }}
                        </span>
                    </div>
                    <Textarea
                        id="prompt"
                        v-model="form.prompt"
                        rows="5"
                        class="min-h-32"
                        placeholder="A lighthouse on a cliff at dawn, painted in watercolour"
                        :aria-invalid="form.errors.prompt ? true : undefined"
                        required
                    />
                    <InputError :message="form.errors.prompt" />
                </div>

                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">Size</legend>
                    <div class="grid grid-cols-3 gap-2">
                        <label
                            v-for="size in sizes"
                            :key="size.value"
                            class="flex cursor-pointer flex-col items-center gap-2 rounded-lg border p-3 text-center text-xs transition-colors hover:bg-accent has-checked:border-primary has-checked:bg-accent has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50"
                        >
                            <input
                                v-model="form.size"
                                type="radio"
                                name="size"
                                :value="size.value"
                                class="sr-only"
                            />
                            <span
                                class="block rounded-sm border-2 border-current"
                                :style="{
                                    width: `${size.width / 64}px`,
                                    height: `${size.height / 64}px`,
                                }"
                                aria-hidden="true"
                            />
                            <span class="font-medium">{{ size.label }}</span>
                            <span class="text-muted-foreground tabular-nums"
                                >{{ size.width }}×{{ size.height }}</span
                            >
                        </label>
                    </div>
                    <InputError :message="form.errors.size" />
                </fieldset>

                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">Quality</legend>
                    <div class="grid grid-cols-3 gap-2">
                        <label
                            v-for="quality in qualities"
                            :key="quality.value"
                            class="flex cursor-pointer flex-col items-center gap-1 rounded-lg border p-3 text-center text-xs transition-colors hover:bg-accent has-checked:border-primary has-checked:bg-accent has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50"
                        >
                            <input
                                v-model="form.quality"
                                type="radio"
                                name="quality"
                                :value="quality.value"
                                class="sr-only"
                            />
                            <span class="font-medium">{{ quality.label }}</span>
                            <span
                                v-if="maxPriceByQuality[quality.value]"
                                class="text-muted-foreground tabular-nums"
                            >
                                up to {{ maxPriceByQuality[quality.value] }}
                            </span>
                        </label>
                    </div>
                    <InputError :message="form.errors.quality" />
                </fieldset>

                <div class="grid gap-2">
                    <div class="flex items-baseline justify-between">
                        <Label
                            >Reference images
                            <span class="font-normal text-muted-foreground"
                                >(optional)</span
                            ></Label
                        >
                        <span class="text-xs text-muted-foreground">
                            {{ form.reference_images.length }} /
                            {{ MAX_REFERENCE_IMAGES }}
                        </span>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Public image URLs to guide the result. Each adds
                        {{ pricing?.tokens_per_reference_image ?? 765 }} input
                        tokens.
                    </p>
                    <div
                        v-for="(_, index) in form.reference_images"
                        :key="index"
                        class="grid gap-1"
                    >
                        <div class="flex gap-2">
                            <Input
                                v-model="form.reference_images[index]"
                                type="url"
                                inputmode="url"
                                :placeholder="REFERENCE_IMAGE_PLACEHOLDER"
                                :aria-label="`Reference image ${index + 1} URL`"
                                :aria-invalid="
                                    errors[`reference_images.${index}`]
                                        ? true
                                        : undefined
                                "
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                :aria-label="`Remove reference image ${index + 1}`"
                                @click="removeReferenceImage(index)"
                            >
                                <X />
                            </Button>
                        </div>
                        <InputError
                            :message="errors[`reference_images.${index}`]"
                        />
                    </div>
                    <InputError :message="form.errors.reference_images" />
                    <Button
                        v-if="
                            form.reference_images.length < MAX_REFERENCE_IMAGES
                        "
                        type="button"
                        variant="outline"
                        size="sm"
                        class="w-fit"
                        @click="addReferenceImage"
                    >
                        <ImagePlus />
                        Add reference image
                    </Button>
                </div>

                <Alert
                    v-if="errors.credits || errors.generation"
                    variant="destructive"
                >
                    <TriangleAlert />
                    <AlertTitle>We couldn't start this generation</AlertTitle>
                    <AlertDescription>{{
                        errors.credits ?? errors.generation
                    }}</AlertDescription>
                </Alert>

                <Button
                    type="submit"
                    size="lg"
                    class="w-full"
                    :disabled="!canSubmit"
                >
                    <Spinner v-if="form.processing" />
                    <Sparkles v-else />
                    Generate
                    <span v-if="estimate" class="tabular-nums opacity-80">
                        · up to
                        {{ formatUsd(estimate.maxCharge, { rounding: 'up' }) }}
                    </span>
                </Button>
            </form>

            <aside class="lg:col-span-2">
                <div
                    class="rounded-xl border bg-card p-6 shadow-xs lg:sticky lg:top-4"
                >
                    <CostEstimate
                        v-if="estimate"
                        :estimate="estimate"
                        :balance="balance"
                        :markup-basis-points="markupBasisPoints"
                    />
                    <Alert v-else>
                        <TriangleAlert />
                        <AlertTitle
                            >Pricing is temporarily unavailable</AlertTitle
                        >
                        <AlertDescription>
                            We can't reach Pixelforge's price list right now, so
                            we can't quote a generation. Please try again in a
                            moment.
                        </AlertDescription>
                    </Alert>
                </div>
            </aside>
        </div>

        <section aria-labelledby="recent-heading" class="space-y-4">
            <div class="flex items-center justify-between">
                <h2
                    id="recent-heading"
                    class="text-lg font-semibold tracking-tight"
                >
                    Recent generations
                </h2>
                <Link
                    v-if="generations.length"
                    :href="history()"
                    class="text-sm text-muted-foreground underline-offset-4 hover:underline"
                >
                    View all
                </Link>
            </div>
            <div
                v-if="generations.length"
                class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
            >
                <GenerationCard
                    v-for="generation in generations"
                    :key="generation.id"
                    :generation="generation"
                />
            </div>
            <p
                v-else
                class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
            >
                Your generations will appear here.
            </p>
        </section>
    </div>
</template>
