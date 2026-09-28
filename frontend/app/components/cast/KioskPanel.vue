<script setup lang="ts">
import type { KioskPanel } from '~/types/cast'

/**
 * One panel of a screen, drawn at any size: every size is relative to the panel box (container query units), so the
 * same component fills a TV and the live preview of the editor.
 */
const props = withDefaults(defineProps<{ panel: KioskPanel, timezone?: string, locale?: string, accent?: string }>(), {
  timezone: 'Europe/Paris',
  locale: 'fr-FR',
  accent: '#0ea5e9',
})

const s = computed(() => props.panel.settings as Record<string, string & number & boolean>)
const d = computed(() => (props.panel.data ?? {}) as Record<string, string>)
const items = computed(() => ((props.panel.data?.items ?? []) as { label: string, value: string, unit?: string }[]))
const view = computed(() => props.panel.type === 'source' ? String(s.value.view) : props.panel.type)

// Clock, refreshed every second
const now = ref(new Date())
let timer: ReturnType<typeof setInterval> | undefined
onMounted(() => (timer = setInterval(() => (now.value = new Date()), 1000)))
onBeforeUnmount(() => clearInterval(timer))
const time = computed(() => new Intl.DateTimeFormat(props.locale, { hour: '2-digit', minute: '2-digit', second: s.value.showSeconds ? '2-digit' : undefined, timeZone: props.timezone }).format(now.value))
const date = computed(() => new Intl.DateTimeFormat(props.locale, { weekday: 'long', day: 'numeric', month: 'long', timeZone: props.timezone }).format(now.value))

function day(iso: string | undefined | null, withTime = false): string {
  if (!iso) return ''
  const value = /^\d{4}-\d{2}-\d{2}$/.test(iso) ? new Date(`${iso}T12:00:00`) : new Date(iso)
  return new Intl.DateTimeFormat(props.locale, { weekday: 'long', day: 'numeric', month: 'long', ...(withTime ? { hour: '2-digit', minute: '2-digit' } : {}), timeZone: /^\d{4}-\d{2}-\d{2}$/.test(iso) ? undefined : props.timezone }).format(value)
}

// Weather: Open-Meteo, from the browser only (the API never calls it), cached 30 minutes
interface Forecast { current: { temperature_2m: number, weather_code: number }, daily: { time: string[], weather_code: number[], temperature_2m_max: number[], temperature_2m_min: number[] } }
const forecast = ref<Forecast | null>(null)
async function loadWeather() {
  if (props.panel.type !== 'weather') return
  const key = `cast-weather:${s.value.latitude},${s.value.longitude},${s.value.days}`
  try {
    const cached = JSON.parse(localStorage.getItem(key) ?? 'null') as { at: number, data: Forecast } | null
    if (cached && Date.now() - cached.at < 30 * 60_000) {
      forecast.value = cached.data
      return
    }
    const url = `https://api.open-meteo.com/v1/forecast?latitude=${s.value.latitude}&longitude=${s.value.longitude}&current=temperature_2m,weather_code&daily=weather_code,temperature_2m_max,temperature_2m_min&timezone=auto&forecast_days=${s.value.days}`
    forecast.value = await $fetch<Forecast>(url)
    localStorage.setItem(key, JSON.stringify({ at: Date.now(), data: forecast.value }))
  }
  catch {
    const stale = JSON.parse(localStorage.getItem(key) ?? 'null') as { data: Forecast } | null
    forecast.value = stale?.data ?? null
  }
}
onMounted(loadWeather)
watch(() => [s.value.latitude, s.value.longitude, s.value.days], loadWeather)

const wifi = computed(() => view.value === 'wifi'
  ? { ssid: String(props.panel.type === 'source' ? d.value.ssid ?? '' : s.value.ssid), password: String(props.panel.type === 'source' ? d.value.password ?? '' : s.value.password ?? ''), security: props.panel.type === 'source' ? 'WPA' : String(s.value.security ?? 'WPA'), showQr: props.panel.type === 'source' ? true : Boolean(s.value.showQr) }
  : null)

const textView = computed(() => {
  if (props.panel.type === 'richtext') return { title: s.value.title, text: String(s.value.text ?? '') }
  if (['rules', 'tips', 'contacts', 'text'].includes(view.value)) {
    const titles: Record<string, string> = { rules: 'Règlement', tips: 'Bonnes adresses', contacts: 'Contacts', text: '' }
    return { title: s.value.title || d.value.title || titles[view.value], text: String(d.value.text ?? '') }
  }
  return null
})
</script>

<template>
  <div class="kiosk-panel relative flex size-full flex-col items-center justify-center overflow-hidden p-[6cqmin] text-center" :style="{ '--accent': accent }">
    <!-- Welcome -->
    <template v-if="view === 'welcome'">
      <UIcon name="i-lucide-hand" class="mb-[3cqmin] size-[10cqmin] text-(--accent)" />
      <h1 class="text-[9cqmin] leading-tight font-bold">
        <template v-if="panel.type === 'source'">
          {{ s.title || (d.firstName ? `Bienvenue ${d.firstName} !` : 'Bienvenue !') }}
        </template>
        <template v-else>
          {{ s.title }}
        </template>
      </h1>
      <p v-if="panel.type === 'source' && d.title" class="mt-[1.5cqmin] text-[4cqmin] opacity-70">
        {{ d.title }}
      </p>
      <p v-if="panel.type === 'source' ? d.text : s.text" class="mt-[4cqmin] max-w-[85cqw] text-[4.5cqmin] whitespace-pre-line opacity-90">
        {{ panel.type === 'source' ? d.text : s.text }}
      </p>
    </template>

    <!-- Current guest -->
    <template v-else-if="view === 'guest'">
      <p class="text-[4.5cqmin] opacity-70">
        {{ s.title || d.title }}
      </p>
      <h1 class="mt-[2cqmin] text-[11cqmin] leading-tight font-bold">
        Bonjour {{ d.firstName }}
      </h1>
      <p v-if="d.departure" class="mt-[4cqmin] text-[4.5cqmin]">
        <UIcon name="i-lucide-calendar" class="mr-[1cqmin] size-[4.5cqmin] align-middle text-(--accent)" />
        Départ le {{ day(d.departure) }}<template v-if="d.checkOut">
          avant {{ d.checkOut }}
        </template>
      </p>
    </template>

    <!-- Next arrival -->
    <template v-else-if="view === 'next_arrival'">
      <UIcon name="i-lucide-luggage" class="mb-[3cqmin] size-[10cqmin] text-(--accent)" />
      <p class="text-[5cqmin] opacity-70">
        {{ s.title || 'Prochaine arrivée' }}
      </p>
      <h1 class="mt-[2cqmin] text-[8cqmin] leading-tight font-bold first-letter:uppercase">
        {{ d.at ? day(d.at, true) : day(d.date) }}
      </h1>
    </template>

    <!-- Clock -->
    <template v-else-if="view === 'clock'">
      <p v-if="s.label" class="text-[5cqmin] opacity-70">
        {{ s.label }}
      </p>
      <p class="text-[22cqmin] leading-none font-bold tabular-nums">
        {{ time }}
      </p>
      <p v-if="s.showDate" class="mt-[3cqmin] text-[6cqmin] first-letter:uppercase">
        {{ date }}
      </p>
    </template>

    <!-- Weather -->
    <template v-else-if="view === 'weather'">
      <p class="text-[5cqmin] opacity-70">
        {{ s.label }}
      </p>
      <template v-if="forecast">
        <div class="mt-[2cqmin] flex items-center gap-[4cqmin]">
          <UIcon :name="weatherCode(forecast.current.weather_code).icon" class="size-[18cqmin] text-(--accent)" />
          <div class="text-left">
            <p class="text-[16cqmin] leading-none font-bold">
              {{ Math.round(forecast.current.temperature_2m) }}°
            </p>
            <p class="text-[4.5cqmin]">
              {{ weatherCode(forecast.current.weather_code).label }}
            </p>
          </div>
        </div>
        <div class="mt-[5cqmin] flex gap-[6cqmin]">
          <div v-for="(t, i) in forecast.daily.time" :key="t" class="flex flex-col items-center">
            <p class="text-[3.5cqmin] opacity-70 first-letter:uppercase">
              {{ i === 0 ? 'Aujourd’hui' : new Intl.DateTimeFormat(locale, { weekday: 'short' }).format(new Date(`${t}T12:00:00`)) }}
            </p>
            <UIcon :name="weatherCode(forecast.daily.weather_code[i] ?? 0).icon" class="my-[1cqmin] size-[7cqmin]" />
            <p class="text-[3.5cqmin] tabular-nums">
              {{ Math.round(forecast.daily.temperature_2m_min[i] ?? 0) }}° / {{ Math.round(forecast.daily.temperature_2m_max[i] ?? 0) }}°
            </p>
          </div>
        </div>
      </template>
      <p v-else class="mt-[4cqmin] text-[4cqmin] opacity-60">
        Prévisions indisponibles.
      </p>
    </template>

    <!-- Wi-Fi -->
    <template v-else-if="wifi">
      <div class="flex items-center gap-[6cqmin]">
        <div class="text-left">
          <UIcon name="i-lucide-wifi" class="size-[10cqmin] text-(--accent)" />
          <p class="mt-[2cqmin] text-[4cqmin] opacity-70">
            Réseau
          </p>
          <p class="text-[7cqmin] font-bold break-all">
            {{ wifi.ssid }}
          </p>
          <template v-if="wifi.password">
            <p class="mt-[2cqmin] text-[4cqmin] opacity-70">
              Mot de passe
            </p>
            <p class="font-mono text-[6cqmin] font-semibold break-all">
              {{ wifi.password }}
            </p>
          </template>
        </div>
        <div v-if="wifi.showQr" class="w-[32cqmin] shrink-0">
          <CastQrCode :value="wifiQrPayload(wifi.ssid, wifi.password, wifi.security)" />
          <p class="mt-[1.5cqmin] text-[3cqmin] opacity-70">
            Scannez pour vous connecter
          </p>
        </div>
      </div>
    </template>

    <!-- Checkout -->
    <template v-else-if="view === 'checkout'">
      <UIcon name="i-lucide-door-open" class="mb-[3cqmin] size-[10cqmin] text-(--accent)" />
      <h1 class="text-[8cqmin] font-bold">
        Départ<template v-if="(panel.type === 'source' ? d.checkOut : s.time)">
          avant {{ panel.type === 'source' ? d.checkOut : s.time }}
        </template>
      </h1>
      <p v-if="panel.type === 'source' ? d.text : s.text" class="mt-[4cqmin] max-w-[85cqw] text-[4.5cqmin] whitespace-pre-line">
        {{ panel.type === 'source' ? d.text : s.text }}
      </p>
    </template>

    <!-- Image -->
    <template v-else-if="view === 'image'">
      <img :src="s.url" alt="" class="absolute inset-0 size-full" :class="s.fit === 'contain' ? 'object-contain' : 'object-cover'" referrerpolicy="no-referrer">
      <p v-if="s.caption" class="absolute inset-x-0 bottom-0 bg-black/55 px-[4cqmin] py-[2cqmin] text-[4cqmin] text-white">
        {{ s.caption }}
      </p>
    </template>

    <!-- Text -->
    <template v-else-if="textView">
      <h1 v-if="textView.title" class="mb-[4cqmin] text-[7cqmin] font-bold">
        {{ textView.title }}
      </h1>
      <!-- eslint-disable-next-line vue/no-v-html -- escaped by renderRichText -->
      <div class="kiosk-richtext max-w-[88cqw] text-left text-[4.5cqmin]" v-html="renderRichText(textView.text)" />
    </template>

    <!-- QR code -->
    <template v-else-if="view === 'qrcode'">
      <h1 v-if="s.title" class="mb-[3cqmin] text-[7cqmin] font-bold">
        {{ s.title }}
      </h1>
      <div class="w-[45cqmin]">
        <CastQrCode :value="String(s.value)" />
      </div>
      <p v-if="s.caption" class="mt-[3cqmin] text-[4.5cqmin] opacity-80">
        {{ s.caption }}
      </p>
    </template>

    <!-- Values -->
    <template v-else-if="view === 'items'">
      <h1 v-if="s.title || d.title" class="mb-[4cqmin] text-[7cqmin] font-bold">
        {{ s.title || d.title }}
      </h1>
      <div class="grid w-full max-w-[90cqw] grid-cols-2 gap-[3cqmin]">
        <div v-for="item in items" :key="item.label" class="rounded-[2cqmin] bg-current/8 p-[3cqmin]">
          <p class="text-[3.5cqmin] opacity-70">
            {{ item.label }}
          </p>
          <p class="text-[7cqmin] font-bold tabular-nums">
            {{ item.value }}<span v-if="item.unit" class="ml-[1cqmin] text-[4cqmin] font-normal opacity-70">{{ item.unit }}</span>
          </p>
        </div>
      </div>
    </template>

    <p v-else class="text-[4cqmin] opacity-60">
      Rien à afficher pour le moment.
    </p>
  </div>
</template>

<style scoped>
.kiosk-richtext :deep(p) { margin-bottom: 0.8em; }
.kiosk-richtext :deep(ul) { list-style: disc; padding-left: 1.2em; margin-bottom: 0.8em; }
.kiosk-richtext :deep(strong) { color: var(--accent); }
</style>
