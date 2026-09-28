<script setup lang="ts">
import type { KioskPayload } from '~/types/cast'

/**
 * Kiosk of a screen (no account): fullscreen playlist, asked again every minute and at "reloadAt" (next schedule
 * change, next guest…). Offline tolerant: the last payload is kept in localStorage and shown while the API is
 * unreachable. Click or tap: fullscreen.
 */
definePageMeta({ layout: false })
useHead({ title: 'Rocket Cast', meta: [{ name: 'robots', content: 'noindex, nofollow' }, { name: 'referrer', content: 'no-referrer' }] })

const route = useRoute()
const config = useRuntimeConfig()
const token = String(route.params.token)
const cacheKey = `cast-screen:${token}`

const payload = ref<KioskPayload | null>(null)
const offline = ref(false)
const invalid = ref<string | null>(null)
let timer: ReturnType<typeof setTimeout> | undefined
const loadedAt = Date.now()

function readCache(): KioskPayload | null {
  try {
    return JSON.parse(localStorage.getItem(cacheKey) ?? 'null') as KioskPayload | null
  }
  catch {
    return null
  }
}

async function load() {
  clearTimeout(timer)
  try {
    const fresh = await $fetch<KioskPayload>(`${config.public.apiBase}/api/public/screens/${token}`, { headers: { Accept: 'application/json' } })
    if (fresh.version !== payload.value?.version || JSON.stringify(fresh.screen) !== JSON.stringify(payload.value?.screen) || JSON.stringify(fresh.playlist) !== JSON.stringify(payload.value?.playlist)) {
      payload.value = fresh
    }
    else if (payload.value) {
      payload.value.reloadAt = fresh.reloadAt
    }
    offline.value = false
    invalid.value = null
    try {
      localStorage.setItem(cacheKey, JSON.stringify(fresh))
      localStorage.setItem('cast-screen-token', token)
    }
    catch { /* storage full or disabled: no offline copy */ }
  }
  catch (error) {
    const status = (error as { statusCode?: number }).statusCode
    if (status === 404) {
      invalid.value = 'Ce lien d’écran est invalide ou a été révoqué.'
      payload.value = null
      localStorage.removeItem(cacheKey)
      if (localStorage.getItem('cast-screen-token') === token) localStorage.removeItem('cast-screen-token')
    }
    else {
      offline.value = true
      payload.value ??= readCache()
    }
  }
  schedule()
}

function schedule() {
  // A new version of the interface once a day
  if (Date.now() - loadedAt > 24 * 3600_000 && !offline.value) {
    window.location.reload()
    return
  }
  const poll = (payload.value?.pollSeconds ?? 60) * 1000
  const reloadIn = payload.value ? new Date(payload.value.reloadAt).getTime() - Date.now() + 1000 : poll
  const delay = invalid.value ? 5 * 60_000 : offline.value ? 30_000 : Math.max(5000, Math.min(poll, reloadIn))
  timer = setTimeout(load, delay)
}

// Keep the screen awake where the browser allows it
let wakeLock: { release: () => Promise<void> } | null = null
async function stayAwake() {
  try {
    wakeLock = await (navigator as Navigator & { wakeLock?: { request: (t: 'screen') => Promise<{ release: () => Promise<void> }> } }).wakeLock?.request('screen') ?? null
  }
  catch { /* not allowed: nothing to do */ }
}
function onVisibility() {
  if (document.visibilityState === 'visible') {
    stayAwake()
    load()
  }
}

function fullscreen() {
  if (!document.fullscreenElement) document.documentElement.requestFullscreen?.().catch(() => {})
}

// A portrait screen on a landscape display (TV turned on its side): the content is rotated
const viewport = reactive({ width: 1920, height: 1080 })
function measure() {
  viewport.width = window.innerWidth
  viewport.height = window.innerHeight
}
const rotated = computed(() => payload.value?.screen?.orientation === 'portrait' && viewport.width > viewport.height)

onMounted(() => {
  payload.value = readCache()
  measure()
  window.addEventListener('resize', measure)
  document.addEventListener('visibilitychange', onVisibility)
  stayAwake()
  load()
})
onBeforeUnmount(() => {
  clearTimeout(timer)
  window.removeEventListener('resize', measure)
  document.removeEventListener('visibilitychange', onVisibility)
  wakeLock?.release().catch(() => {})
})
</script>

<template>
  <div class="fixed inset-0 cursor-none overflow-hidden bg-black text-white select-none" data-testid="kiosk" @click="fullscreen">
    <div
      class="absolute"
      :style="rotated
        ? { width: `${viewport.height}px`, height: `${viewport.width}px`, top: `${(viewport.height - viewport.width) / 2}px`, left: `${(viewport.width - viewport.height) / 2}px`, transform: 'rotate(90deg)' }
        : { inset: '0' }"
    >
      <div v-if="invalid" class="flex size-full flex-col items-center justify-center gap-4 p-8 text-center">
        <UIcon name="i-lucide-link-2-off" class="size-16 text-amber-400" />
        <p class="text-2xl">
          {{ invalid }}
        </p>
        <p class="text-lg opacity-70">
          Appairez cet écran de nouveau : ouvrez /pair.
        </p>
      </div>
      <div v-else-if="payload && payload.screen && !payload.screen.enabled" class="flex size-full flex-col items-center justify-center gap-4 text-center">
        <UIcon name="i-lucide-monitor-off" class="size-16 opacity-60" />
        <p class="text-2xl opacity-80">
          Écran désactivé
        </p>
      </div>
      <CastKioskPlayer
        v-else-if="payload"
        :panels="payload.panels"
        :timezone="payload.screen?.timezone"
        :locale="payload.screen?.locale"
        :accent="payload.playlist?.accent"
        :theme="payload.playlist?.theme"
      >
        <template #empty>
          <UIcon name="i-lucide-cast" class="size-[12cqmin]" :style="{ color: payload.playlist?.accent ?? '#0ea5e9' }" />
          <p class="text-[5cqmin] font-semibold">
            {{ payload.screen?.name }}
          </p>
          <p class="text-[3cqmin] opacity-60">
            {{ payload.playlist ? 'Aucun panneau programmé en ce moment.' : 'Aucune playlist n’est associée à cet écran.' }}
          </p>
        </template>
      </CastKioskPlayer>
      <div v-else class="flex size-full items-center justify-center">
        <UIcon name="i-lucide-loader-circle" class="size-12 animate-spin opacity-60" />
      </div>
    </div>
    <div v-if="offline" class="absolute top-3 right-3 flex items-center gap-1.5 rounded-full bg-black/60 px-3 py-1 text-xs text-amber-300" title="Connexion perdue : dernier contenu reçu">
      <UIcon name="i-lucide-wifi-off" class="size-3.5" /> Hors ligne
    </div>
  </div>
</template>
