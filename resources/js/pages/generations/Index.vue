<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, Images, Sparkles } from '@lucide/vue';
import GenerationCard from '@/components/generations/GenerationCard.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { useLiveGenerations } from '@/composables/useLiveGenerations';
import { dashboard } from '@/routes';
import { index as history } from '@/routes/generations';
import type { Generation, Paginated } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'History',
                href: history(),
            },
        ],
    },
});

const props = defineProps<{
    generations: Paginated<Generation>;
}>();

useLiveGenerations(() => props.generations.data, ['generations']);
</script>

<template>
    <Head title="History" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="History"
                description="Every generation with its live status, what we estimated and what it actually cost."
            />
            <Button as-child>
                <Link :href="dashboard()">
                    <Sparkles />
                    New generation
                </Link>
            </Button>
        </div>

        <div
            v-if="generations.data.length"
            class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
        >
            <GenerationCard
                v-for="generation in generations.data"
                :key="generation.id"
                :generation="generation"
            />
        </div>

        <div
            v-else
            class="flex flex-col items-center gap-3 rounded-xl border border-dashed p-12 text-center"
        >
            <Images class="size-8 text-muted-foreground" aria-hidden="true" />
            <p class="font-medium">No generations yet</p>
            <p class="text-sm text-muted-foreground">
                Your images and what they cost will show up here.
            </p>
            <Button as-child variant="outline">
                <Link :href="dashboard()">Generate your first image</Link>
            </Button>
        </div>

        <nav
            v-if="generations.meta.last_page > 1"
            class="flex items-center justify-between gap-4 text-sm"
            aria-label="Pagination"
        >
            <Button as-child variant="outline" size="sm">
                <Link
                    v-if="generations.links.prev"
                    :href="generations.links.prev"
                    preserve-scroll
                    prefetch
                >
                    <ChevronLeft />
                    Newer
                </Link>
                <span v-else class="pointer-events-none opacity-50"
                    ><ChevronLeft />Newer</span
                >
            </Button>
            <span class="text-muted-foreground tabular-nums">
                Page {{ generations.meta.current_page }} of
                {{ generations.meta.last_page }}
            </span>
            <Button as-child variant="outline" size="sm">
                <Link
                    v-if="generations.links.next"
                    :href="generations.links.next"
                    preserve-scroll
                    prefetch
                >
                    Older
                    <ChevronRight />
                </Link>
                <span v-else class="pointer-events-none opacity-50"
                    >Older<ChevronRight
                /></span>
            </Button>
        </nav>
    </div>
</template>
