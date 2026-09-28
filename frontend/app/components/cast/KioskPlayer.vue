<script setup lang="ts">
import type { KioskPanel } from '~/types/cast'

/**
 * Plays panels in turn, each for its duration, with a cross-fade and a progress bar. "pinned" shows one panel only
 * (the panel being edited). Fills its parent: give it a size.
 */
const props = withDefaults(defineProps<{
  panels: KioskPanel[]
  timezone?: string
  locale?: string
  accent?: string
  theme?: 'dark' | 'light'
  pinned?: string | null
  showProgress?: boolean
}>(), { timezone: 'Europe/Paris', locale: 'fr-FR', accent: '#0ea5e9', theme: 'dark', pinned: null, showProgress: true })

const index = ref(0)
const startedAt = ref(Date.now())
const now = ref(Date.now())
let timer: ReturnType<typeof setInterval> | undefined

const current = computed<KioskPanel | undefined>(() => {
  if (props.pinned) return props.panels.find(p => p.id === props.pinned) ?? props.panels[0]
  return props.panels[index.value % Math.max(1, props.panels.length)]
})
const progress = computed(() => current.value ? Math.min(1, (now.value - startedAt.value) / (current.value.duration * 1000)) : 0)

function tick() {
  now.value = Date.now()
  if (props.pinned || props.panels.length < 2 || !current.value) return
  if (now.value - startedAt.value >= current.value.duration * 1000) {
    index.value = (index.value + 1) % props.panels.length
    startedAt.value = now.value
  }
}
onMounted(() => (timer = setInterval(tick, 250)))
onBeforeUnmount(() => clearInterval(timer))

// New payload: stay on the same panel when it is still there
watch(() => props.panels.map(p => p.id).join(','), (_, old) => {
  const previousId = old?.split(',')[index.value % Math.max(1, old.split(',').length)]
  const kept = props.panels.findIndex(p => p.id === previousId)
  if (kept >= 0) index.value = kept
  else {
    index.value = 0
    startedAt.value = Date.now()
  }
})

const colors = computed(() => props.theme === 'light'
  ? { background: '#f8fafc', color: '#0f172a' }
  : { background: '#0b1120', color: '#f8fafc' })
</script>

<template>
  <div class="kiosk-player relative size-full overflow-hidden" :style="{ ...colors, containerType: 'size' }">
    <div class="pointer-events-none absolute inset-0 opacity-30" :style="{ background: `radial-gradient(circle at 15% 10%, ${accent}55, transparent 55%)` }" />
    <Transition name="kiosk-fade" mode="out-in">
      <CastKioskPanel v-if="current" :key="current.id" :panel="current" :timezone="timezone" :locale="locale" :accent="accent" class="relative" />
      <div v-else class="relative flex size-full flex-col items-center justify-center gap-[2cqmin] text-center">
        <slot name="empty">
          <UIcon name="i-lucide-cast" class="size-[12cqmin]" :style="{ color: accent }" />
          <p class="text-[4cqmin] opacity-70">
            Rien à afficher pour le moment.
          </p>
        </slot>
      </div>
    </Transition>
    <div v-if="showProgress && !pinned && panels.length > 1" class="absolute inset-x-0 bottom-0 h-[0.6cqmin] bg-current/10">
      <div class="h-full transition-[width] duration-200 ease-linear" :style="{ width: `${progress * 100}%`, background: accent }" />
    </div>
  </div>
</template>

<style scoped>
.kiosk-fade-enter-active, .kiosk-fade-leave-active { transition: opacity 0.6s ease; }
.kiosk-fade-enter-from, .kiosk-fade-leave-to { opacity: 0; }
</style>
