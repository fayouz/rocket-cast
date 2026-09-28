<script setup lang="ts">
import type { KioskPayload, Panel, PanelType, Playlist, Source } from '~/types/cast'

/** Playlist editor: panels (order, duration, schedule, settings) on the left, live preview on the right. */
const appName = useAppConfig().rocket.name
const route = useRoute()
const api = useApi()
const toast = useToast()
const id = String(route.params.id)

const { data: playlist } = await useAsyncData(`playlist-${id}`, () => api<Playlist>(`/api/playlists/${id}`))
const { data: sources } = await useAsyncData('playlist-sources', () => api<Source[]>('/api/sources'), { default: () => [] })
useHead({ title: () => `${playlist.value?.name ?? 'Playlist'} · ${appName}` })

const form = reactive({ name: '', description: '', accent: '#0ea5e9', theme: 'dark' as 'dark' | 'light' })
const panels = ref<Panel[]>([])
const saved = ref('')
function reset(p: Playlist) {
  Object.assign(form, { name: p.name, description: p.description ?? '', accent: p.accent, theme: p.theme })
  panels.value = structuredClone(p.panels ?? [])
  saved.value = JSON.stringify([form, panels.value])
}
if (playlist.value) reset(playlist.value)
const dirty = computed(() => JSON.stringify([form, panels.value]) !== saved.value)

const selected = ref<string | null>(panels.value[0]?.id ?? null)
const selectedPanel = computed(() => panels.value.find(p => p.id === selected.value) ?? null)

function add(type: PanelType) {
  const panel = newPanel(type)
  if (type === 'source' && sources.value[0]) {
    panel.settings.sourceId = sources.value[0].id
    panel.settings.view = sources.value[0].capabilities[0]?.id ?? ''
  }
  panels.value.push(panel)
  selected.value = panel.id
}
const addItems = computed(() => [Object.entries(PANEL_TYPES).map(([type, t]) => ({ label: t.label, icon: t.icon, onSelect: () => add(type as PanelType) }))])

function move(index: number, delta: number) {
  const target = index + delta
  if (target < 0 || target >= panels.value.length) return
  const [panel] = panels.value.splice(index, 1)
  panels.value.splice(target, 0, panel!)
}
function removePanel(index: number) {
  const [panel] = panels.value.splice(index, 1)
  if (panel?.id === selected.value) selected.value = panels.value[Math.max(0, index - 1)]?.id ?? null
}
function duplicatePanel(index: number) {
  const copy = { ...structuredClone(panels.value[index]!), id: Math.random().toString(36).slice(2, 12) }
  panels.value.splice(index + 1, 0, copy)
  selected.value = copy.id
}

function toggleSchedule(panel: Panel, on: boolean) {
  panel.schedule = on ? { days: [1, 2, 3, 4, 5, 6, 7], from: '08:00', until: '20:00' } : null
}
function toggleDay(panel: Panel, day: number) {
  if (!panel.schedule) return
  const days = panel.schedule.days.includes(day) ? panel.schedule.days.filter(d => d !== day) : [...panel.schedule.days, day].sort()
  if (days.length) panel.schedule.days = days
}

function panelTitle(panel: Panel): string {
  const s = panel.settings as Record<string, string>
  if (panel.type === 'source') {
    const source = sources.value.find(x => x.id === s.sourceId)
    return `${source?.name ?? 'Source'} · ${source?.capabilities.find(c => c.id === s.view)?.label ?? s.view}`
  }
  return s.title || s.label || s.ssid || s.caption || s.value || PANEL_TYPES[panel.type].label
}
const selectedSource = computed(() => selectedPanel.value?.type === 'source' ? sources.value.find(s => s.id === selectedPanel.value!.settings.sourceId) ?? null : null)
const sourceItems = computed(() => sources.value.map(s => ({ label: `${s.name} (${s.typeName})`, value: s.id })))
function onSourceChange(panel: Panel, sourceId: string) {
  panel.settings.sourceId = sourceId
  const source = sources.value.find(s => s.id === sourceId)
  if (!source?.capabilities.some(c => c.id === panel.settings.view)) panel.settings.view = source?.capabilities[0]?.id ?? ''
}

// Live preview: the panels as the API resolves them (sources, schedule), debounced
const preview = ref<KioskPayload | null>(null)
const previewError = ref<string | null>(null)
const previewTimezone = ref('Europe/Paris')
const pinned = ref(true)
let debounce: ReturnType<typeof setTimeout> | undefined
async function refreshPreview() {
  try {
    preview.value = await api<KioskPayload>('/api/playlists/preview', { method: 'POST', body: { panels: panels.value, timezone: previewTimezone.value } })
    previewError.value = null
  }
  catch (error) {
    previewError.value = apiErrorMessage(error)
  }
}
watch([panels, previewTimezone], () => {
  clearTimeout(debounce)
  debounce = setTimeout(refreshPreview, 400)
}, { deep: true })
onMounted(refreshPreview)
const previewPanels = computed(() => (preview.value?.panels ?? []).filter(p => !pinned.value ? p.activeNow !== false : true))
const previewOrientation = ref<'landscape' | 'portrait'>('landscape')

const saving = ref(false)
async function save() {
  saving.value = true
  try {
    const result = await api<Playlist>(`/api/playlists/${id}`, { method: 'PATCH', body: { ...form, description: form.description || null, panels: panels.value } })
    playlist.value = result
    reset(result)
    toast.add({ title: 'Playlist enregistrée', description: result.screenCount ? `Les ${result.screenCount} écran(s) la reçoivent d’ici une minute.` : undefined, color: 'success' })
  }
  catch (error) {
    toast.add({ title: 'Enregistrement impossible', description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    saving.value = false
  }
}
onBeforeRouteLeave(() => !dirty.value || window.confirm('Quitter sans enregistrer les modifications ?'))
</script>

<template>
  <UDashboardPanel id="playlist-editor">
    <template #header>
      <UDashboardNavbar :title="playlist?.name ?? 'Playlist'">
        <template #leading>
          <UDashboardSidebarCollapse />
          <UButton icon="i-lucide-arrow-left" color="neutral" variant="ghost" to="/playlists" aria-label="Playlists" />
        </template>
        <template #right>
          <UBadge v-if="dirty" label="Modifications non enregistrées" color="warning" variant="subtle" />
          <UButton icon="i-lucide-save" label="Enregistrer" :loading="saving" :disabled="!dirty" @click="save" />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UAlert v-if="!playlist" color="warning" variant="subtle" title="Playlist introuvable" />
      <div v-else class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
        <div class="flex flex-col gap-4">
          <UCard>
            <div class="grid gap-3 sm:grid-cols-2">
              <UFormField label="Nom" required>
                <UInput v-model="form.name" class="w-full" />
              </UFormField>
              <div class="grid grid-cols-2 gap-3">
                <UFormField label="Couleur">
                  <div class="flex items-center gap-2">
                    <input v-model="form.accent" type="color" class="size-8 cursor-pointer rounded border border-default bg-transparent" aria-label="Couleur d’accent">
                    <UInput v-model="form.accent" class="w-full font-mono" />
                  </div>
                </UFormField>
                <UFormField label="Thème">
                  <USelect v-model="form.theme" :items="[{ label: 'Sombre', value: 'dark' }, { label: 'Clair', value: 'light' }]" class="w-full" />
                </UFormField>
              </div>
              <UFormField label="Description" class="sm:col-span-2">
                <UInput v-model="form.description" class="w-full" />
              </UFormField>
            </div>
          </UCard>

          <div class="flex items-center justify-between">
            <h2 class="font-semibold text-highlighted">
              Panneaux <span class="text-sm font-normal text-muted">· {{ formatDuration(panels.filter(p => p.enabled).reduce((t, p) => t + p.duration, 0)) }} par boucle</span>
            </h2>
            <UDropdownMenu :items="addItems">
              <UButton icon="i-lucide-plus" label="Ajouter un panneau" variant="soft" />
            </UDropdownMenu>
          </div>

          <ul class="flex flex-col gap-2" data-testid="panels">
            <li
              v-for="(panel, index) in panels"
              :key="panel.id"
              class="rounded-lg border bg-default transition"
              :class="panel.id === selected ? 'border-primary ring-1 ring-primary' : 'border-default'"
            >
              <div class="flex cursor-pointer items-center gap-2 p-2" @click="selected = panel.id">
                <UIcon :name="PANEL_TYPES[panel.type].icon" class="size-5 shrink-0 text-primary" />
                <div class="min-w-0 flex-1" :class="{ 'opacity-50': !panel.enabled }">
                  <p class="truncate text-sm font-medium text-highlighted">
                    {{ panelTitle(panel) }}
                  </p>
                  <p class="truncate text-xs text-muted">
                    {{ PANEL_TYPES[panel.type].label }} · {{ panel.duration }} s · {{ scheduleLabel(panel.schedule) }}
                    <template v-if="preview?.panels.find(p => p.id === panel.id)?.activeNow === false">
                      · hors programmation
                    </template>
                  </p>
                </div>
                <USwitch v-model="panel.enabled" size="sm" aria-label="Actif" @click.stop />
                <UButton icon="i-lucide-arrow-up" size="xs" color="neutral" variant="ghost" :disabled="index === 0" aria-label="Monter" @click.stop="move(index, -1)" />
                <UButton icon="i-lucide-arrow-down" size="xs" color="neutral" variant="ghost" :disabled="index === panels.length - 1" aria-label="Descendre" @click.stop="move(index, 1)" />
                <UButton icon="i-lucide-copy" size="xs" color="neutral" variant="ghost" aria-label="Dupliquer" @click.stop="duplicatePanel(index)" />
                <UButton icon="i-lucide-trash-2" size="xs" color="error" variant="ghost" aria-label="Supprimer" @click.stop="removePanel(index)" />
              </div>

              <div v-if="panel.id === selected" class="grid gap-3 border-t border-default p-3 sm:grid-cols-2">
                <template v-if="panel.type === 'source'">
                  <UAlert v-if="!sources.length" class="sm:col-span-2" color="warning" variant="subtle" title="Aucune source" description="Un administrateur doit d’abord déclarer une source (menu Sources)." />
                  <template v-else>
                    <UFormField label="Source">
                      <USelect :model-value="String(panel.settings.sourceId)" :items="sourceItems" class="w-full" @update:model-value="(v: string) => onSourceChange(panel, v)" />
                    </UFormField>
                    <UFormField label="Vue">
                      <USelect v-model="panel.settings.view as string" :items="(selectedSource?.capabilities ?? []).map(c => ({ label: c.label, value: c.id }))" class="w-full" />
                    </UFormField>
                    <UFormField label="Titre (facultatif)" class="sm:col-span-2">
                      <UInput v-model="panel.settings.title as string" class="w-full" />
                    </UFormField>
                  </template>
                </template>
                <template v-else>
                  <UFormField v-for="field in PANEL_TYPES[panel.type].fields" :key="field.key" :label="field.label" :help="field.help" :class="{ 'sm:col-span-2': field.type === 'textarea' || field.type === 'url' }">
                    <UTextarea v-if="field.type === 'textarea'" v-model="panel.settings[field.key] as string" :rows="4" class="w-full" autoresize />
                    <USwitch v-else-if="field.type === 'switch'" v-model="panel.settings[field.key] as boolean" />
                    <USelect v-else-if="field.type === 'select'" v-model="panel.settings[field.key] as string" :items="field.options" class="w-full" />
                    <UInput v-else-if="field.type === 'number'" v-model.number="panel.settings[field.key] as number" type="number" :step="field.step" class="w-full" />
                    <UInput v-else v-model="panel.settings[field.key] as string" :type="field.type === 'time' ? 'time' : 'text'" :placeholder="field.placeholder" class="w-full" />
                  </UFormField>
                </template>

                <UFormField label="Durée (secondes)">
                  <UInput v-model.number="panel.duration" type="number" :min="5" :max="3600" class="w-full" />
                </UFormField>
                <UFormField label="Programmation">
                  <USwitch :model-value="panel.schedule !== null" label="Seulement certains jours / heures" @update:model-value="(on: boolean) => toggleSchedule(panel, on)" />
                </UFormField>
                <div v-if="panel.schedule" class="flex flex-wrap items-end gap-3 sm:col-span-2">
                  <div class="flex gap-1">
                    <UButton
                      v-for="d in DAYS"
                      :key="d.value"
                      size="xs"
                      :label="d.label"
                      :variant="panel.schedule.days.includes(d.value) ? 'solid' : 'outline'"
                      :color="panel.schedule.days.includes(d.value) ? 'primary' : 'neutral'"
                      @click="toggleDay(panel, d.value)"
                    />
                  </div>
                  <UFormField label="De">
                    <UInput v-model="panel.schedule.from" type="time" />
                  </UFormField>
                  <UFormField label="À" help="Avant « de » : jusqu’au lendemain.">
                    <UInput v-model="panel.schedule.until" type="time" />
                  </UFormField>
                </div>
              </div>
            </li>
          </ul>
          <p v-if="!panels.length" class="text-sm text-muted">
            Aucun panneau : ajoutez-en un.
          </p>
        </div>

        <div class="flex flex-col gap-3 xl:sticky xl:top-4 xl:self-start">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-highlighted">
              Aperçu en direct
            </h2>
            <div class="flex items-center gap-2">
              <USwitch v-model="pinned" label="Panneau sélectionné" size="sm" />
              <USelect v-model="previewOrientation" :items="[{ label: 'Paysage', value: 'landscape' }, { label: 'Portrait', value: 'portrait' }]" size="sm" />
              <USelectMenu v-model="previewTimezone" :items="TIMEZONES" size="sm" class="w-40" />
            </div>
          </div>
          <div class="mx-auto w-full overflow-hidden rounded-xl border-4 border-neutral-800 shadow-lg" :class="previewOrientation === 'portrait' ? 'aspect-[9/16] max-w-sm' : 'aspect-video'" data-testid="preview">
            <CastKioskPlayer :panels="previewPanels" :pinned="pinned ? selected : null" :accent="form.accent" :theme="form.theme" :timezone="previewTimezone" />
          </div>
          <UAlert v-if="previewError" color="error" variant="subtle" icon="i-lucide-triangle-alert" :description="previewError" />
          <p v-else class="text-xs text-muted">
            {{ pinned ? 'Le panneau sélectionné, avec les données actuelles de ses sources.' : 'La boucle telle que les écrans la jouent maintenant (panneaux programmés à cette heure, sources avec des données).' }}
          </p>
        </div>
      </div>
    </template>
  </UDashboardPanel>
</template>
