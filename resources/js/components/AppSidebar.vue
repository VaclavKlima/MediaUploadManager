<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Activity, Film, FolderSearch, LayoutGrid, Upload } from '@lucide/vue';
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
import type { NavItem } from '@/types';

const page = usePage();

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Movies',
        href: moviesIndex(),
        icon: Film,
    },
    {
        title: 'Upload movie',
        href: movieUpload(),
        icon: Upload,
    },
    {
        title: 'Library scan',
        href: scanIndex(),
        icon: FolderSearch,
    },
    ...(page.props.auth.user.is_administrator
        ? [
              {
                  title: 'Operations',
                  href: operations(),
                  icon: Activity,
                  external: true,
              },
          ]
        : []),
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

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
