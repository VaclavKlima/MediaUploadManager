<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';
import {
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import type { NavItem } from '@/types';

defineProps<{
    title: string;
    icon: LucideIcon;
    items: NavItem[];
}>();

const { isCurrentUrl } = useCurrentUrl();
</script>

<template>
    <SidebarGroup class="px-2 py-1.5 first:pt-0">
        <SidebarGroupLabel
            class="gap-2 px-2 text-xs font-semibold text-sidebar-foreground/65 group-data-[collapsible=icon]:mt-0! group-data-[collapsible=icon]:size-8 group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:p-0 group-data-[collapsible=icon]:opacity-100"
        >
            <component :is="icon" class="size-4 shrink-0" />
            <span class="group-data-[collapsible=icon]:hidden">{{
                title
            }}</span>
        </SidebarGroupLabel>
        <SidebarGroupContent>
            <SidebarMenu
                class="relative gap-0 before:absolute before:top-4 before:bottom-4 before:left-4 before:w-px before:bg-sidebar-border"
            >
                <SidebarMenuItem v-for="item in items" :key="item.title">
                    <SidebarMenuButton
                        as-child
                        class="group/nav-item relative h-8 rounded-md pr-2 pl-8 text-[0.8125rem] hover:bg-sidebar-accent/60 data-[active=true]:bg-transparent data-[active=true]:text-sidebar-primary"
                        :is-active="isCurrentUrl(item.href)"
                        :tooltip="item.title"
                    >
                        <a
                            v-if="item.external"
                            :href="toUrl(item.href)"
                            :aria-current="
                                isCurrentUrl(item.href) ? 'page' : undefined
                            "
                        >
                            <span
                                aria-hidden="true"
                                class="absolute left-[0.8125rem] z-10 size-1.5 rounded-full bg-sidebar-border ring-2 ring-sidebar transition-colors group-hover/nav-item:bg-sidebar-foreground/35 group-data-[active=true]/nav-item:bg-sidebar-primary"
                            ></span>
                            <span
                                class="group-data-[collapsible=icon]:hidden"
                                >{{ item.title }}</span
                            >
                        </a>
                        <Link
                            v-else
                            :href="item.href"
                            :aria-current="
                                isCurrentUrl(item.href) ? 'page' : undefined
                            "
                        >
                            <span
                                aria-hidden="true"
                                class="absolute left-[0.8125rem] z-10 size-1.5 rounded-full bg-sidebar-border ring-2 ring-sidebar transition-colors group-hover/nav-item:bg-sidebar-foreground/35 group-data-[active=true]/nav-item:bg-sidebar-primary"
                            ></span>
                            <span
                                class="group-data-[collapsible=icon]:hidden"
                                >{{ item.title }}</span
                            >
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarGroupContent>
    </SidebarGroup>
</template>
