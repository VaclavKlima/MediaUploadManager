<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Activity, Film, LayoutGrid, Upload } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { operations } from '@/routes';
import { index as scanIndex } from '@/routes/library_scans';
import { index as moviesIndex, upload as movieUpload } from '@/routes/movies';
import { index as seriesIndex, upload as seriesUpload } from '@/routes/series';
import type { NavGroup } from '@/types';

const page = usePage();

const navigationGroups = computed<NavGroup[]>(() => [
    {
        title: 'Overview',
        icon: LayoutGrid,
        items: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
    {
        title: 'Library',
        icon: Film,
        items: [
            {
                title: 'Movies',
                href: moviesIndex(),
            },
            {
                title: 'Shows',
                href: seriesIndex(),
            },
        ],
    },
    {
        title: 'Add media',
        icon: Upload,
        items: [
            {
                title: 'Upload movie',
                href: movieUpload(),
            },
            {
                title: 'Upload show episodes',
                href: seriesUpload(),
            },
        ],
    },
    {
        title: 'System',
        icon: Activity,
        items: [
            {
                title: 'Library scan',
                href: scanIndex(),
            },
            ...(page.props.auth.user.is_administrator
                ? [
                      {
                          title: 'Operations',
                          href: operations(),
                          external: true,
                      },
                  ]
                : []),
        ],
    },
]);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent class="gap-0 pb-3">
            <NavMain
                v-for="group in navigationGroups"
                :key="group.title"
                :title="group.title"
                :icon="group.icon"
                :items="group.items"
            />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
