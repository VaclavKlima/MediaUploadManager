<script setup lang="ts">
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItem } from '@/types';

const { breadcrumbs = [], contentClass } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
    contentClass?: string;
}>();
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs" :content-class="contentClass">
        <slot />
    </AppLayout>
</template>
