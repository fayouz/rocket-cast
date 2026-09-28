<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { Playlist, Screen, ScreenLink } from '~/types/cast'

const appName = useAppConfig().rocket.name
useHead({ title: `Écrans · ${appName}` })

const api = useApi()
const toast = useToast()
const route = useRoute()
const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')
const USwitch = resolveComponent('USwitch')
const UDropdownMenu = resolveComponent('UDropdownMenu')

const { data: screens, status, refresh } = await useAsyncData('screens', () => api<Screen[]>('/api/screens'), { default: () => [] })
const { data: playlists } = await useAsyncData('screen-playlists', () => api<Playlist[]>('/api/playlists'), { default: () => [] })
const playlistItems = computed(() => [{ label: 'Aucune playlist', value: '' }, ...playlists.value.map(p => ({ label: p.name, value: p.id }))])

// Online status is refreshed every 30 seconds
let refresher: ReturnType<typeof setInterval> | undefined
onMounted(() => (refresher = setInterval(() => refresh(), 30_000)))
onBeforeUnmount(() => clearInterval(refresher))

async function patch(screen: Screen, body: Record<string, unknown>) {
  try {
    Object.assign(screen, await api<Screen>(`/api/screens/${screen.id}`, { method: 'PATCH', body }))
    return true
  }
  catch (error) {
    toast.add({ title: 'Mise à jour impossible', description: apiErrorMessage(error), color: 'error' })
    return false
  }
}

// The kiosk link, shown once
const link = ref<(ScreenLink & { name: string }) | null>(null)
const origin = ref('')
onMounted(() => (origin.value = window.location.origin))
const kioskUrl = computed(() => link.value ? `${origin.value}${link.value.kioskPath}` : '')
async function copy(text: string) {
  await navigator.clipboard.writeText(text)
  toast.add({ title: 'Lien copié', color: 'success', icon: 'i-lucide-copy-check' })
}

async function rotate(screen: Screen) {
  try {
    const result = await api<Screen & ScreenLink>(`/api/screens/${screen.id}/token`, { method: 'POST' })
    link.value = { ...result, name: screen.name }
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Nouveau lien impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

async function revoke(screen: Screen) {
  try {
    await api(`/api/screens/${screen.id}/token`, { method: 'DELETE' })
    toast.add({ title: 'Lien révoqué', description: `${screen.name} n’affiche plus rien jusqu’à un nouveau lien.`, color: 'success' })
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Révocation impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

const columns: TableColumn<Screen>[] = [
  {
    accessorKey: 'name',
    header: 'Écran',
    cell: ({ row }) => h('div', [
      h('p', { class: 'flex items-center gap-1.5 font-medium text-highlighted' }, [
        row.original.name,
        row.original.orientation === 'portrait' && h(UBadge, { label: 'Portrait', color: 'neutral', variant: 'subtle', size: 'sm' }),
      ]),
      h('p', { class: 'text-xs text-muted' }, [row.original.placeName, row.original.location, row.original.timezone].filter(Boolean).join(' · ')),
    ]),
  },
  {
    id: 'status',
    header: 'État',
    cell: ({ row }) => h('div', [
      h(UBadge, { label: row.original.online ? 'En ligne' : 'Hors ligne', color: row.original.online ? 'success' : 'neutral', variant: 'subtle', icon: row.original.online ? 'i-lucide-wifi' : 'i-lucide-wifi-off' }),
      h('p', { class: 'mt-0.5 text-xs text-muted', title: row.original.lastUserAgent ?? '' }, `Vu ${relativeTime(row.original.lastSeenAt)}`),
    ]),
  },
  {
    id: 'playlist',
    header: 'Playlist',
    cell: ({ row }) => row.original.playlist
      ? h(resolveComponent('NuxtLink'), { to: `/playlists/${row.original.playlist.id}`, class: 'text-primary hover:underline' }, () => row.original.playlist!.name)
      : h('span', { class: 'text-muted' }, '—'),
  },
  {
    id: 'link',
    header: 'Lien',
    cell: ({ row }) => row.original.hasToken
      ? h('span', { class: 'font-mono text-xs text-muted' }, `/s/…${row.original.tokenHint}`)
      : h(UBadge, { label: 'Aucun lien', color: 'warning', variant: 'subtle' }),
  },
  {
    accessorKey: 'enabled',
    header: 'Actif',
    cell: ({ row }) => h(USwitch, { 'modelValue': row.original.enabled, 'onUpdate:modelValue': (value: boolean) => patch(row.original, { enabled: value }) }),
  },
  {
    id: 'actions',
    cell: ({ row }) => h('div', { class: 'flex justify-end gap-1' }, [
      h(UButton, { 'icon': 'i-lucide-pencil', 'color': 'neutral', 'variant': 'ghost', 'aria-label': 'Modifier', 'onClick': () => edit(row.original) }),
      h(UDropdownMenu, {
        items: [
          [
            { label: 'Nouveau lien d’écran', icon: 'i-lucide-refresh-cw', onSelect: () => rotate(row.original) },
            { label: 'Réappairer (code)', icon: 'i-lucide-link', onSelect: () => openPair(row.original) },
            { label: 'Révoquer le lien', icon: 'i-lucide-link-2-off', disabled: !row.original.hasToken, onSelect: () => revoke(row.original) },
          ],
          [{ label: 'Supprimer', icon: 'i-lucide-trash-2', color: 'error', onSelect: () => (toDelete.value = row.original) }],
        ],
        content: { align: 'end' },
      }, () => h(UButton, { 'icon': 'i-lucide-ellipsis-vertical', 'color': 'neutral', 'variant': 'ghost', 'aria-label': 'Actions' })),
    ]),
  },
]

// Create / edit
const formOpen = ref(false)
const editing = ref<Screen | null>(null)
const empty = () => ({ name: '', location: '', placeId: '', placeName: '', orientation: 'landscape' as Screen['orientation'], timezone: 'Europe/Paris', locale: 'fr-FR', playlistId: '', enabled: true })
const form = reactive(empty())
const timezones = computed(() => {
  const all = typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : TIMEZONES
  return Array.from(new Set([...TIMEZONES, ...all, form.timezone]))
})

function create() {
  editing.value = null
  Object.assign(form, empty(), { playlistId: playlists.value[0]?.id ?? '' })
  formOpen.value = true
}

function edit(screen: Screen) {
  editing.value = screen
  Object.assign(form, {
    name: screen.name, location: screen.location ?? '', placeId: screen.placeId ?? '', placeName: screen.placeName ?? '', orientation: screen.orientation, timezone: screen.timezone,
    locale: screen.locale, playlistId: screen.playlist?.id ?? '', enabled: screen.enabled,
  })
  formOpen.value = true
}

async function submit() {
  const body = { ...form, location: form.location || null, placeId: form.placeId.trim() || null, placeName: form.placeName.trim() || null, playlistId: form.playlistId || null }
  if (editing.value) {
    if (await patch(editing.value, body)) {
      formOpen.value = false
      await refresh()
    }
    return
  }
  try {
    const created = await api<Screen & ScreenLink>('/api/screens', { method: 'POST', body })
    formOpen.value = false
    link.value = { ...created, name: created.name }
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Création impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

// Pairing: the code shown by /pair on the screen
const pairOpen = ref(false)
const pairTarget = ref<Screen | null>(null)
const pairForm = reactive({ code: '', name: '', playlistId: '', timezone: 'Europe/Paris', orientation: 'landscape' as Screen['orientation'] })

function openPair(screen: Screen | null = null) {
  pairTarget.value = screen
  Object.assign(pairForm, { code: '', name: '', playlistId: playlists.value[0]?.id ?? '', timezone: 'Europe/Paris', orientation: 'landscape' })
  pairOpen.value = true
}
onMounted(() => {
  if (route.query.pair !== undefined) openPair()
})

async function pair() {
  const body: Record<string, unknown> = pairTarget.value
    ? { code: pairForm.code, screenId: pairTarget.value.id }
    : { code: pairForm.code, name: pairForm.name || undefined, playlistId: pairForm.playlistId || null, timezone: pairForm.timezone, orientation: pairForm.orientation }
  try {
    const screen = await api<Screen>('/api/screens/pair', { method: 'POST', body })
    pairOpen.value = false
    toast.add({ title: 'Écran appairé', description: `${screen.name} va afficher sa playlist dans quelques secondes.`, color: 'success', icon: 'i-lucide-cast' })
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Appairage impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

const toDelete = ref<Screen | null>(null)
async function remove() {
  const screen = toDelete.value!
  toDelete.value = null
  try {
    await api(`/api/screens/${screen.id}`, { method: 'DELETE' })
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Suppression impossible', description: apiErrorMessage(error), color: 'error' })
  }
}
</script>

<template>
  <UDashboardPanel id="screens">
    <template #header>
      <UDashboardNavbar title="Écrans">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton icon="i-lucide-link" label="Appairer un écran" color="neutral" variant="outline" @click="openPair()" />
          <UButton icon="i-lucide-plus" label="Nouvel écran" @click="create" />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UAlert
        icon="i-lucide-info"
        variant="subtle"
        color="neutral"
        title="Installer un écran"
        description="Sur la TV ou la tablette, ouvrez /pair (plein écran, navigateur ou mode kiosque) puis « Appairer un écran » ici avec le code affiché. Ou créez l’écran et ouvrez son lien /s/… — il n’est montré qu’une fois : un nouveau lien révoque l’ancien."
      />
      <UTable :data="screens" :columns="columns" :loading="status === 'pending'" empty="Aucun écran : créez-en un ou appairez une TV." />

      <UModal v-model:open="formOpen" :title="editing ? `Modifier ${editing.name}` : 'Nouvel écran'" :ui="{ content: 'max-w-2xl' }">
        <template #body>
          <form id="screen-form" class="grid gap-3 sm:grid-cols-2" @submit.prevent="submit">
            <UFormField label="Nom" required>
              <UInput v-model="form.name" class="w-full" placeholder="TV du hall" />
            </UFormField>
            <UFormField label="Emplacement">
              <UInput v-model="form.location" class="w-full" placeholder="Accueil, rez-de-chaussée" />
            </UFormField>
            <UFormField label="Lieu Rocket Place (identifiant)" help="Facultatif : UUID du lieu, pour retrouver l'écran depuis Rocket Host.">
              <UInput v-model="form.placeId" class="w-full font-mono" placeholder="0192f7c4-…" />
            </UFormField>
            <UFormField label="Nom du lieu">
              <UInput v-model="form.placeName" class="w-full" placeholder="Le port" :disabled="!form.placeId.trim()" />
            </UFormField>
            <UFormField label="Playlist" class="sm:col-span-2">
              <USelect v-model="form.playlistId" :items="playlistItems" class="w-full" />
            </UFormField>
            <UFormField label="Orientation" help="Portrait sur une TV en paysage : le contenu est pivoté.">
              <USelect v-model="form.orientation" :items="[{ label: 'Paysage', value: 'landscape' }, { label: 'Portrait', value: 'portrait' }]" class="w-full" />
            </UFormField>
            <UFormField label="Fuseau horaire" help="Horloge et programmation des panneaux.">
              <USelectMenu v-model="form.timezone" :items="timezones" class="w-full" />
            </UFormField>
            <UFormField label="Langue (dates)">
              <USelect v-model="form.locale" :items="[{ label: 'Français', value: 'fr-FR' }, { label: 'English', value: 'en-GB' }, { label: 'Español', value: 'es-ES' }, { label: 'Deutsch', value: 'de-DE' }, { label: 'Italiano', value: 'it-IT' }]" class="w-full" />
            </UFormField>
            <div class="flex items-end">
              <USwitch v-model="form.enabled" label="Actif" />
            </div>
          </form>
        </template>
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton label="Annuler" color="neutral" variant="ghost" @click="formOpen = false" />
            <UButton type="submit" form="screen-form" :label="editing ? 'Enregistrer' : 'Créer'" />
          </div>
        </template>
      </UModal>

      <UModal v-model:open="pairOpen" :title="pairTarget ? `Réappairer ${pairTarget.name}` : 'Appairer un écran'" description="Ouvrez /pair sur l’écran : il affiche un code valable 15 minutes.">
        <template #body>
          <form id="pair-form" class="grid gap-3 sm:grid-cols-2" @submit.prevent="pair">
            <UFormField label="Code affiché par l’écran" required class="sm:col-span-2">
              <UInput v-model="pairForm.code" class="w-full font-mono text-lg uppercase tracking-widest" placeholder="ABC-DEF" autofocus autocomplete="off" />
            </UFormField>
            <template v-if="!pairTarget">
              <UFormField label="Nom de l’écran">
                <UInput v-model="pairForm.name" class="w-full" placeholder="TV du salon" />
              </UFormField>
              <UFormField label="Playlist">
                <USelect v-model="pairForm.playlistId" :items="playlistItems" class="w-full" />
              </UFormField>
              <UFormField label="Orientation">
                <USelect v-model="pairForm.orientation" :items="[{ label: 'Paysage', value: 'landscape' }, { label: 'Portrait', value: 'portrait' }]" class="w-full" />
              </UFormField>
              <UFormField label="Fuseau horaire">
                <USelectMenu v-model="pairForm.timezone" :items="timezones" class="w-full" />
              </UFormField>
            </template>
            <p v-else class="text-sm text-muted sm:col-span-2">
              L’écran reçoit un nouveau lien ; l’ancien cesse de fonctionner.
            </p>
          </form>
        </template>
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton label="Annuler" color="neutral" variant="ghost" @click="pairOpen = false" />
            <UButton type="submit" form="pair-form" icon="i-lucide-link" label="Appairer" :disabled="pairForm.code.replace(/[^a-z0-9]/gi, '').length !== 6" />
          </div>
        </template>
      </UModal>

      <UModal :open="link !== null" :title="`Lien de ${link?.name}`" description="Ouvrez ce lien en plein écran sur l’écran. Il n’est affiché qu’une fois : copiez-le maintenant." @update:open="(value: boolean) => { if (!value) link = null }">
        <template #body>
          <div class="flex flex-col gap-3">
            <UInput :model-value="kioskUrl" readonly class="w-full font-mono" />
            <div class="flex gap-2">
              <UButton icon="i-lucide-copy" label="Copier" @click="copy(kioskUrl)" />
              <UButton icon="i-lucide-external-link" label="Ouvrir" color="neutral" variant="outline" :to="link?.kioskPath" target="_blank" />
            </div>
            <UAlert color="warning" variant="subtle" icon="i-lucide-shield-alert" description="Toute personne ayant ce lien voit l’écran (y compris le Wi-Fi affiché). En cas de doute, générez un nouveau lien." />
          </div>
        </template>
      </UModal>

      <UModal :open="toDelete !== null" :title="`Supprimer ${toDelete?.name} ?`" description="Son lien cesse de fonctionner." @update:open="(value: boolean) => { if (!value) toDelete = null }">
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton label="Annuler" color="neutral" variant="ghost" @click="toDelete = null" />
            <UButton label="Supprimer" color="error" @click="remove" />
          </div>
        </template>
      </UModal>
    </template>
  </UDashboardPanel>
</template>
