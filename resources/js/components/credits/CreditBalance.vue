<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Wallet } from '@lucide/vue';
import { computed } from 'vue';
import BuyCreditsDialog from '@/components/credits/BuyCreditsDialog.vue';
import {
    SidebarGroup,
    SidebarGroupContent,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { formatUsd, formatUsdExact, toNanos } from '@/lib/money';

const page = usePage();

const balance = computed(() =>
    page.props.credits
        ? formatUsd(toNanos(page.props.credits.balance), {
              decimals: 2,
              rounding: 'down',
          })
        : null,
);
const exactBalance = computed(() =>
    page.props.credits ? formatUsdExact(page.props.credits.balance) : undefined,
);
</script>

<template>
    <SidebarGroup
        v-if="balance !== null"
        class="group-data-[collapsible=icon]:p-0"
    >
        <SidebarGroupContent>
            <SidebarMenu>
                <SidebarMenuItem>
                    <BuyCreditsDialog>
                        <SidebarMenuButton
                            :tooltip="`Credits: ${balance}. Buy more`"
                            :title="exactBalance"
                        >
                            <Wallet />
                            <span>Credits</span>
                            <span class="ml-auto font-medium tabular-nums">{{
                                balance
                            }}</span>
                        </SidebarMenuButton>
                    </BuyCreditsDialog>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarGroupContent>
    </SidebarGroup>
</template>
