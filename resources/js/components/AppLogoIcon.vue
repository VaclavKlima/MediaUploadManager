<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 48 48"
        :class="className"
        v-bind="$attrs"
    >
        <path
            d="M13.5 6.5v35m21-35v35M6.5 15h7m21 0h7m-35 18h7m21 0h7"
            fill="none"
            stroke="currentColor"
            stroke-width="3"
            stroke-linecap="round"
        />
        <rect
            x="5.5"
            y="5.5"
            width="37"
            height="37"
            rx="7.5"
            fill="none"
            stroke="currentColor"
            stroke-width="3"
        />
        <path
            d="M24 33V16m-7 7 7-7 7 7"
            fill="none"
            stroke="currentColor"
            stroke-width="3.5"
            stroke-linecap="round"
            stroke-linejoin="round"
        />
    </svg>
</template>
