<script setup lang="ts">
/**
 * Pairing of a fresh screen (no account): shows a short code to type in Rocket Cast → Écrans → Appairer, then
 * receives its kiosk link by itself and opens it. A screen paired before goes straight to its link (?new=1: new code).
 */
definePageMeta({ layout: false })
useHead({ title: 'Appairer cet écran · Rocket Cast', meta: [{ name: 'robots', content: 'noindex, nofollow' }] })

interface Pairing { code: string, secret: string, expiresAt: string, pollSeconds: number }

const config = useRuntimeConfig()
const route = useRoute()
const pairing = ref<Pairing | null>(null)
const error = ref<string | null>(null)
const adminUrl = ref('')
let timer: ReturnType<typeof setTimeout> | undefined

async function start() {
  clearTimeout(timer)
  error.value = null
  try {
    pairing.value = await $fetch<Pairing>(`${config.public.apiBase}/api/public/pairings`, { method: 'POST', headers: { Accept: 'application/json' } })
    timer = setTimeout(poll, pairing.value.pollSeconds * 1000)
  }
  catch (e) {
    error.value = apiErrorMessage(e)
    timer = setTimeout(start, 30_000)
  }
}

async function poll() {
  const current = pairing.value
  if (!current) return
  if (new Date(current.expiresAt).getTime() <= Date.now()) return start()
  try {
    const result = await $fetch<{ status: 'pending' | 'paired', token?: string }>(`${config.public.apiBase}/api/public/pairings/poll`, {
      method: 'POST', body: { secret: current.secret }, headers: { Accept: 'application/json' },
    })
    if (result.status === 'paired' && result.token) {
      localStorage.setItem('cast-screen-token', result.token)
      await navigateTo(`/s/${result.token}`, { replace: true })
      return
    }
  }
  catch (e) {
    if ((e as { statusCode?: number }).statusCode === 404) return start()
  }
  timer = setTimeout(poll, current.pollSeconds * 1000)
}

onMounted(() => {
  adminUrl.value = `${window.location.origin}/screens?pair=1`
  const known = localStorage.getItem('cast-screen-token')
  if (known && route.query.new === undefined) {
    navigateTo(`/s/${known}`, { replace: true })
    return
  }
  start()
})
onBeforeUnmount(() => clearTimeout(timer))
</script>

<template>
  <div class="fixed inset-0 flex flex-col items-center justify-center gap-8 bg-[#0b1120] p-8 text-center text-white" data-testid="pair">
    <div class="flex items-center gap-3 text-2xl font-semibold">
      <UIcon name="i-lucide-cast" class="size-9 text-sky-400" /> Rocket Cast
    </div>
    <template v-if="pairing">
      <p class="text-xl opacity-80">
        Code d’appairage de cet écran
      </p>
      <p class="font-mono text-[min(18vw,10rem)] leading-none font-bold tracking-widest text-sky-300" data-testid="pairing-code">
        {{ pairing.code }}
      </p>
      <p class="max-w-2xl text-lg opacity-80">
        Dans Rocket Cast, ouvrez <strong>Écrans → Appairer un écran</strong> et saisissez ce code.
        <span class="mt-2 block font-mono text-base opacity-70">{{ adminUrl }}</span>
      </p>
      <p class="flex items-center gap-2 text-sm opacity-60">
        <UIcon name="i-lucide-loader-circle" class="size-4 animate-spin" /> En attente… le code change toutes les 15 minutes.
      </p>
    </template>
    <UAlert v-else-if="error" color="warning" variant="subtle" icon="i-lucide-wifi-off" title="Serveur injoignable" :description="`${error} Nouvel essai dans 30 secondes.`" class="max-w-lg" />
    <UIcon v-else name="i-lucide-loader-circle" class="size-12 animate-spin opacity-60" />
  </div>
</template>
