<script setup lang="ts">
import { renderSVG } from 'uqr'

/** QR code drawn in the browser (SVG, no network). */
const props = defineProps<{ value: string }>()
const svg = computed(() => props.value ? renderSVG(props.value, { border: 1, whiteColor: '#ffffff', blackColor: '#000000' }) : '')
</script>

<template>
  <!-- eslint-disable-next-line vue/no-v-html -- SVG generated locally by uqr -->
  <div class="aspect-square overflow-hidden rounded-[2cqmin] bg-white [&>svg]:size-full" role="img" :aria-label="`QR code : ${value}`" v-html="svg" />
</template>
