<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { Source, SourceType } from '~/types/cast'

const appName = useAppConfig().rocket.name
useHead({ title: `Sources · ${appName}` })

const api = useApi()
const toast = useToast()
const { isAdmin } = useAuth()
const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')
const UIcon = resolveComponent('UIcon')
const USwitch = resolveComponent('USwitch')

const { data: sources, status, refresh } = await useAsyncData('sources', () => api<Source[]>('/api/sources'), { default: () => [] })
const { data: types } = await useAsyncData('source-types', () => api<SourceType[]>('/api/source-types'), { default: () => [] })

const tests = reactive<Record<string, { ok: boolean, error: string | null, latencyMs: number, payload: unknown } | 'pending'>>({})
async function test(source: Source) {
  tests[source.id] = 'pending'
  try {
    const result = await api<{ ok: boolean, error: string | null, latencyMs: number, payload: unknown, source: Source }>(`/api/sources/${source.id}/test`, { method: 'POST' })
    tests[source.id] = result
    Object.assign(source, result.source)
  }
  catch (error) {
    tests[source.id] = { ok: false, error: apiErrorMessage(error), latencyMs: 0, payload: null }
  }
}

async function patch(source: Source, body: Record<string, unknown>) {
  try {
    Object.assign(source, await api<Source>(`/api/sources/${source.id}`, { method: 'PATCH', body }))
    return true
  }
  catch (error) {
    toast.add({ title: 'Mise à jour impossible', description: apiErrorMessage(error), color: 'error' })
    return false
  }
}

const payloadOf = ref<{ name: string, payload: unknown } | null>(null)

const columns = computed<TableColumn<Source>[]>(() => [
  {
    accessorKey: 'name',
    header: 'Source',
    cell: ({ row }) => h('div', { class: 'flex items-center gap-2' }, [
      h(UIcon, { name: row.original.icon, class: 'size-5 text-primary' }),
      h('div', [
        h('p', { class: 'font-medium text-highlighted' }, row.original.name),
        h('p', { class: 'max-w-96 text-xs whitespace-normal text-muted' }, `${row.original.typeName} · ${row.original.capabilities.map(c => c.label).join(', ')}`),
      ]),
    ]),
  },
  {
    id: 'state',
    header: 'Données',
    cell: ({ row }) => {
      const result = tests[row.original.id]
      if (result === 'pending') return h(UIcon, { name: 'i-lucide-loader-circle', class: 'size-4 animate-spin text-muted' })
      return h('div', { class: 'max-w-80' }, [
        row.original.lastError
          ? h(UBadge, { label: 'Erreur', color: 'error', variant: 'subtle', icon: 'i-lucide-triangle-alert' })
          : h(UBadge, { label: row.original.fetchedAt ? 'OK' : 'Jamais lue', color: row.original.fetchedAt ? 'success' : 'neutral', variant: 'subtle' }),
        h('p', { class: ['mt-0.5 text-xs', row.original.lastError ? 'text-error' : 'text-muted'] }, row.original.lastError ?? `Lue ${relativeTime(row.original.fetchedAt)} · toutes les ${formatDuration(row.original.refreshSeconds)}`),
      ])
    },
  },
  {
    accessorKey: 'enabled',
    header: 'Active',
    cell: ({ row }) => h(USwitch, { 'modelValue': row.original.enabled, 'disabled': !isAdmin.value, 'onUpdate:modelValue': (value: boolean) => patch(row.original, { enabled: value }) }),
  },
  {
    id: 'actions',
    cell: ({ row }) => h('div', { class: 'flex justify-end gap-1' }, [
      h(UButton, { 'icon': 'i-lucide-eye', 'color': 'neutral', 'variant': 'ghost', 'aria-label': 'Données', 'title': 'Dernières données', 'onClick': () => showPayload(row.original) }),
      ...(isAdmin.value
        ? [
            h(UButton, { 'icon': 'i-lucide-plug-zap', 'color': 'neutral', 'variant': 'ghost', 'aria-label': 'Tester', 'title': 'Lire maintenant', 'onClick': () => test(row.original) }),
            h(UButton, { 'icon': 'i-lucide-pencil', 'color': 'neutral', 'variant': 'ghost', 'aria-label': 'Modifier', 'onClick': () => edit(row.original) }),
            h(UButton, { 'icon': 'i-lucide-trash-2', 'color': 'error', 'variant': 'ghost', 'aria-label': 'Supprimer', 'onClick': () => (toDelete.value = row.original) }),
          ]
        : []),
    ]),
  },
])

async function showPayload(source: Source) {
  try {
    const { payload } = await api<{ payload: unknown }>(`/api/sources/${source.id}/payload`)
    payloadOf.value = { name: source.name, payload }
  }
  catch (error) {
    toast.add({ title: 'Lecture impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

// Create / edit: the form follows the fields of the source type
const formOpen = ref(false)
const editing = ref<Source | null>(null)
const form = reactive({ name: '', type: '', refreshSeconds: 300, enabled: true, config: {} as Record<string, string>, secrets: {} as Record<string, string> })
const type = computed(() => types.value.find(t => t.id === form.type) ?? null)
const typeItems = computed(() => types.value.map(t => ({ label: t.name, value: t.id, icon: t.icon })))

function create() {
  editing.value = null
  Object.assign(form, { name: '', type: types.value[0]?.id ?? '', refreshSeconds: 300, enabled: true, config: {}, secrets: {} })
  formOpen.value = true
}
function edit(source: Source) {
  editing.value = source
  Object.assign(form, { name: source.name, type: source.type, refreshSeconds: source.refreshSeconds, enabled: source.enabled, config: { ...(source.config ?? {}) }, secrets: {} })
  formOpen.value = true
}

async function submit() {
  const secrets: Record<string, string> = {}
  for (const [key, value] of Object.entries(form.secrets)) if (value) secrets[key] = value
  const body = { name: form.name, type: form.type, refreshSeconds: form.refreshSeconds, enabled: form.enabled, config: form.config, secrets }
  try {
    const source = editing.value
      ? await api<Source>(`/api/sources/${editing.value.id}`, { method: 'PATCH', body })
      : await api<Source>('/api/sources', { method: 'POST', body })
    formOpen.value = false
    await refresh()
    const fresh = sources.value.find(s => s.id === source.id)
    if (fresh) await test(fresh)
  }
  catch (error) {
    toast.add({ title: 'Enregistrement impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

async function clearSecret(key: string) {
  if (!editing.value) return
  if (await patch(editing.value, { secrets: { [key]: '' } })) toast.add({ title: 'Identifiant supprimé', color: 'success' })
}

const toDelete = ref<Source | null>(null)
async function remove() {
  const source = toDelete.value!
  toDelete.value = null
  try {
    await api(`/api/sources/${source.id}`, { method: 'DELETE' })
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Suppression impossible', description: apiErrorMessage(error), color: 'error' })
  }
}
</script>

<template>
  <UDashboardPanel id="sources">
    <template #header>
      <UDashboardNavbar title="Sources">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton v-if="isAdmin" icon="i-lucide-plus" label="Nouvelle source" @click="create" />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UAlert
        icon="i-lucide-info"
        variant="subtle"
        color="neutral"
        title="Intégrations"
        description="Les sources alimentent les panneaux « Source » : livret d’accueil et voyageurs de Rocket PMS, valeurs domotiques de Rocket Place, n’importe quelle API JSON. Les jetons sont gardés dans le coffre des secrets (chiffrés) et jamais réaffichés ; les écrans gardent les dernières données si une source ne répond plus."
      />
      <UTable :data="sources" :columns="columns" :loading="status === 'pending'" :empty="isAdmin ? 'Aucune source : ajoutez-en une.' : 'Aucune source : demandez à un administrateur.'" />

      <UModal v-model:open="formOpen" :title="editing ? `Modifier ${editing.name}` : 'Nouvelle source'" :ui="{ content: 'max-w-2xl' }">
        <template #body>
          <form id="source-form" class="grid gap-3 sm:grid-cols-2" @submit.prevent="submit">
            <UFormField label="Type" required class="sm:col-span-2" :help="type?.description">
              <USelect v-model="form.type" :items="typeItems" class="w-full" :disabled="!!editing" />
            </UFormField>
            <UFormField label="Nom" required>
              <UInput v-model="form.name" class="w-full" />
            </UFormField>
            <UFormField label="Rafraîchissement (secondes)" help="Durée pendant laquelle les données sont réutilisées.">
              <UInput v-model.number="form.refreshSeconds" type="number" :min="30" class="w-full" />
            </UFormField>
            <UFormField
              v-for="field in type?.fields ?? []"
              :key="field.key"
              :label="field.label"
              :required="field.required && !(field.secret && editing?.secrets?.[field.key])"
              :help="field.secret && editing?.secrets?.[field.key] ? 'Enregistré dans le coffre (masqué) : laisser vide pour le garder.' : field.help"
              :class="{ 'sm:col-span-2': field.type === 'textarea' || field.type === 'url' }"
            >
              <template v-if="field.secret">
                <div class="flex gap-2">
                  <UInput v-model="form.secrets[field.key]" type="password" autocomplete="new-password" class="w-full" :placeholder="editing?.secrets?.[field.key] ? '••••••••' : field.placeholder" />
                  <UButton v-if="editing?.secrets?.[field.key] && !field.required" icon="i-lucide-x" color="neutral" variant="ghost" aria-label="Supprimer" @click="clearSecret(field.key)" />
                </div>
              </template>
              <UTextarea v-else-if="field.type === 'textarea'" v-model="form.config[field.key]" :rows="4" class="w-full font-mono" :placeholder="field.placeholder" />
              <USelect v-else-if="field.type === 'select'" v-model="form.config[field.key]" :items="field.options" class="w-full" />
              <UInput v-else v-model="form.config[field.key]" :type="field.type === 'email' ? 'email' : 'text'" class="w-full" :placeholder="field.placeholder" />
            </UFormField>
            <UAlert v-if="editing && editing.secretsReadable === false" class="sm:col-span-2" color="warning" variant="subtle" description="Les identifiants enregistrés ne sont plus lisibles dans le coffre des secrets (ROCKET_SECRETS_KEY a changé ou secret supprimé) : saisissez-les à nouveau." />
            <div class="flex items-end sm:col-span-2">
              <USwitch v-model="form.enabled" label="Active" />
            </div>
          </form>
        </template>
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton label="Annuler" color="neutral" variant="ghost" @click="formOpen = false" />
            <UButton type="submit" form="source-form" :label="editing ? 'Enregistrer' : 'Ajouter'" />
          </div>
        </template>
      </UModal>

      <UModal :open="payloadOf !== null" :title="`Données de ${payloadOf?.name}`" :ui="{ content: 'max-w-2xl' }" @update:open="(value: boolean) => { if (!value) payloadOf = null }">
        <template #body>
          <pre class="max-h-[60vh] overflow-auto rounded bg-elevated p-3 text-xs">{{ payloadOf?.payload ? JSON.stringify(payloadOf.payload, null, 2) : 'Aucune donnée lue pour le moment.' }}</pre>
        </template>
      </UModal>

      <UModal :open="toDelete !== null" :title="`Supprimer ${toDelete?.name} ?`" description="Les panneaux qui l’utilisent ne s’afficheront plus." @update:open="(value: boolean) => { if (!value) toDelete = null }">
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
