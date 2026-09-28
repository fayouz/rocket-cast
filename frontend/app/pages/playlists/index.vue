<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { Playlist } from '~/types/cast'

const appName = useAppConfig().rocket.name
useHead({ title: `Playlists · ${appName}` })

const api = useApi()
const toast = useToast()
const UButton = resolveComponent('UButton')
const NuxtLink = resolveComponent('NuxtLink')

const { data: playlists, status, refresh } = await useAsyncData('playlists', () => api<Playlist[]>('/api/playlists'), { default: () => [] })

const createOpen = ref(false)
const name = ref('')
async function create() {
  try {
    const playlist = await api<Playlist>('/api/playlists', { method: 'POST', body: { name: name.value, panels: [newPanel('clock')] } })
    createOpen.value = false
    await navigateTo(`/playlists/${playlist.id}`)
  }
  catch (error) {
    toast.add({ title: 'Création impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

async function duplicate(playlist: Playlist) {
  try {
    await api(`/api/playlists/${playlist.id}/duplicate`, { method: 'POST' })
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Duplication impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

const toDelete = ref<Playlist | null>(null)
async function remove() {
  const playlist = toDelete.value!
  toDelete.value = null
  try {
    await api(`/api/playlists/${playlist.id}`, { method: 'DELETE' })
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Suppression impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

const columns: TableColumn<Playlist>[] = [
  {
    accessorKey: 'name',
    header: 'Playlist',
    cell: ({ row }) => h('div', [
      h(NuxtLink, { to: `/playlists/${row.original.id}`, class: 'flex items-center gap-2 font-medium text-highlighted hover:underline' }, () => [
        h('span', { class: 'size-3 rounded-full', style: { background: row.original.accent } }),
        row.original.name,
      ]),
      h('p', { class: 'text-xs text-muted' }, row.original.description ?? ''),
    ]),
  },
  { id: 'panels', header: 'Panneaux', cell: ({ row }) => `${row.original.panelCount} · ${formatDuration(row.original.duration)} par boucle` },
  { accessorKey: 'screenCount', header: 'Écrans' },
  {
    id: 'actions',
    cell: ({ row }) => h('div', { class: 'flex justify-end gap-1' }, [
      h(UButton, { 'icon': 'i-lucide-pencil', 'color': 'neutral', 'variant': 'ghost', 'aria-label': 'Modifier', 'to': `/playlists/${row.original.id}` }),
      h(UButton, { 'icon': 'i-lucide-copy', 'color': 'neutral', 'variant': 'ghost', 'aria-label': 'Dupliquer', 'title': 'Dupliquer', 'onClick': () => duplicate(row.original) }),
      h(UButton, { 'icon': 'i-lucide-trash-2', 'color': 'error', 'variant': 'ghost', 'aria-label': 'Supprimer', 'onClick': () => (toDelete.value = row.original) }),
    ]),
  },
]
</script>

<template>
  <UDashboardPanel id="playlists">
    <template #header>
      <UDashboardNavbar title="Playlists">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton icon="i-lucide-plus" label="Nouvelle playlist" @click="() => { name = ''; createOpen = true }" />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UTable :data="playlists" :columns="columns" :loading="status === 'pending'" empty="Aucune playlist : créez-en une." />

      <UModal v-model:open="createOpen" title="Nouvelle playlist">
        <template #body>
          <form id="playlist-form" @submit.prevent="create">
            <UFormField label="Nom" required>
              <UInput v-model="name" class="w-full" placeholder="Accueil voyageurs" autofocus />
            </UFormField>
          </form>
        </template>
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton label="Annuler" color="neutral" variant="ghost" @click="createOpen = false" />
            <UButton type="submit" form="playlist-form" label="Créer" :disabled="!name.trim()" />
          </div>
        </template>
      </UModal>

      <UModal :open="toDelete !== null" :title="`Supprimer ${toDelete?.name} ?`" :description="toDelete?.screenCount ? `${toDelete.screenCount} écran(s) n’afficheront plus rien.` : undefined" @update:open="(value: boolean) => { if (!value) toDelete = null }">
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
