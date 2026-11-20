import { usePoll } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import type { Generation } from '@/types';

const IN_FLIGHT_STATUSES = new Set(['queued', 'processing']);

/**
 * Keep generations live by re-fetching only the given props (plus the credit balance) while
 * any of them is still queued or processing. Polling stops once everything is final, pauses
 * in background tabs, and never overlaps requests.
 */
export function useLiveGenerations(
    generations: () => Generation[],
    props: string[],
) {
    const { start, stop } = usePoll(
        3000,
        { only: [...props, 'credits'] },
        { autoStart: false, mode: 'rest', background: 'pause' },
    );

    const hasInFlight = computed(() =>
        generations().some((generation) =>
            IN_FLIGHT_STATUSES.has(generation.status),
        ),
    );

    watch(hasInFlight, (inFlight) => (inFlight ? start() : stop()), {
        immediate: true,
    });

    return { hasInFlight };
}
