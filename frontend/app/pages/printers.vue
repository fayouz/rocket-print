<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { AdminPrinter, PrinterConnector, PrintJob } from '~/types/print'

definePageMeta({ admin: true })
const appName = useAppConfig().rocket.name
useHead({ title: `Imprimantes · ${appName}` })

const api = useApi()
const toast = useToast()
const UBadge = resolveComponent('UBadge')
const USwitch = resolveComponent('USwitch')
const UButton = resolveComponent('UButton')
const UIcon = resolveComponent('UIcon')

const { data: printers, status, refresh } = await useAsyncData('admin-printers', () => api<AdminPrinter[]>('/api/admin/printers', { query: { itemsPerPage: 200 } }), { default: () => [] })

// Result of the last "Tester la connexion", by printer.
const checks = reactive<Record<string, { ok: boolean, detail: string, latencyMs: number } | 'pending'>>({})

async function patch(printer: AdminPrinter, body: Partial<AdminPrinter>) {
  try {
    Object.assign(printer, await api<AdminPrinter>(`/api/admin/printers/${printer.id}`, { method: 'PATCH', body }))
    if (body.defaultPrinter) await refresh()
    return true
  }
  catch (error) {
    toast.add({ title: 'Mise à jour impossible', description: apiErrorMessage(error), color: 'error' })
    return false
  }
}

async function check(printer: AdminPrinter) {
  checks[printer.id] = 'pending'
  try {
    checks[printer.id] = await api<{ ok: boolean, detail: string, latencyMs: number }>(`/api/admin/printers/${printer.id}/check`, { method: 'POST' })
  }
  catch (error) {
    checks[printer.id] = { ok: false, detail: apiErrorMessage(error), latencyMs: 0 }
  }
}

async function testPage(printer: AdminPrinter) {
  try {
    const job = await api<PrintJob>(`/api/admin/printers/${printer.id}/test-page`, { method: 'POST' })
    toast.add({ title: 'Page de test envoyée', description: `Suivez-la dans « Mes impressions » (${PRINT_JOB_STATUS[job.status].label.toLowerCase()}).`, color: 'success', icon: 'i-lucide-printer' })
  }
  catch (error) {
    toast.add({ title: 'Page de test impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

const columns: TableColumn<AdminPrinter>[] = [
  {
    accessorKey: 'name',
    header: 'Imprimante',
    cell: ({ row }) => h('div', [
      h('p', { class: 'flex items-center gap-1.5 font-medium text-highlighted' }, [
        row.original.name,
        row.original.defaultPrinter && h(UBadge, { label: 'Par défaut', color: 'primary', variant: 'subtle', size: 'sm' }),
      ]),
      h('p', { class: 'text-xs text-muted' }, row.original.location ?? ''),
    ]),
  },
  {
    accessorKey: 'connector',
    header: 'Connexion',
    cell: ({ row }) => h('div', [
      h('p', { class: 'text-sm' }, PRINTER_CONNECTORS[row.original.connector].label),
      h('p', { class: 'font-mono text-xs text-muted' }, row.original.uri + (row.original.username ? ` · ${row.original.username}` : '')),
    ]),
  },
  {
    id: 'capabilities',
    header: 'Options',
    cell: ({ row }) => [row.original.duplexSupported ? 'Recto verso' : null, row.original.colorSupported ? 'Couleur' : null].filter(Boolean).join(' · ') || '—',
  },
  {
    id: 'check',
    header: 'Test',
    cell: ({ row }) => {
      const result = checks[row.original.id]
      if (!result) return h('span', { class: 'text-xs text-muted' }, '—')
      if (result === 'pending') return h(UIcon, { name: 'i-lucide-loader-circle', class: 'size-4 animate-spin text-muted' })
      return h('p', { class: ['max-w-64 text-xs', result.ok ? 'text-success' : 'text-error'] }, `${result.ok ? '✓' : '✗'} ${result.detail}`)
    },
  },
  {
    accessorKey: 'enabled',
    header: 'Active',
    cell: ({ row }) => h(USwitch, { 'modelValue': row.original.enabled, 'onUpdate:modelValue': (value: boolean) => patch(row.original, { enabled: value }) }),
  },
  {
    id: 'actions',
    cell: ({ row }) => h('div', { class: 'flex justify-end gap-1' }, [
      h(UButton, { 'icon': 'i-lucide-plug-zap', 'color': 'neutral', 'variant': 'ghost', 'aria-label': 'Tester la connexion', 'title': 'Tester la connexion', 'onClick': () => check(row.original) }),
      h(UButton, { 'icon': 'i-lucide-file-check', 'color': 'neutral', 'variant': 'ghost', 'aria-label': 'Imprimer une page de test', 'title': 'Imprimer une page de test', 'disabled': !row.original.enabled, 'onClick': () => testPage(row.original) }),
      h(UButton, { 'icon': 'i-lucide-pencil', 'color': 'neutral', 'variant': 'ghost', 'aria-label': 'Modifier', 'onClick': () => edit(row.original) }),
      h(UButton, { 'icon': 'i-lucide-trash-2', 'color': 'error', 'variant': 'ghost', 'aria-label': 'Supprimer', 'onClick': () => (toDelete.value = row.original) }),
    ]),
  },
]

// Create / edit
const formOpen = ref(false)
const editing = ref<AdminPrinter | null>(null)
const empty = () => ({
  name: '', description: '', location: '', connector: 'samba' as PrinterConnector, uri: '', username: '', password: '', domain: '',
  colorSupported: false, duplexSupported: true, defaultPrinter: false, enabled: true,
})
const form = reactive(empty())
const removePassword = ref(false)
const connectorItems = Object.entries(PRINTER_CONNECTORS).map(([value, c]) => ({ label: c.label, value }))
const connector = computed(() => PRINTER_CONNECTORS[form.connector])

function create() {
  editing.value = null
  Object.assign(form, empty(), { defaultPrinter: !printers.value.length })
  removePassword.value = false
  formOpen.value = true
}

function edit(printer: AdminPrinter) {
  editing.value = printer
  Object.assign(form, empty(), {
    name: printer.name,
    description: printer.description ?? '',
    location: printer.location ?? '',
    connector: printer.connector,
    uri: printer.uri,
    username: printer.username ?? '',
    domain: printer.domain ?? '',
    colorSupported: printer.colorSupported,
    duplexSupported: printer.duplexSupported,
    defaultPrinter: printer.defaultPrinter,
    enabled: printer.enabled,
  })
  removePassword.value = false
  formOpen.value = true
}

async function submit() {
  const { password, ...rest } = form
  const body: Record<string, unknown> = {
    ...rest,
    description: form.description || null,
    location: form.location || null,
    username: form.connector === 'folder' ? null : form.username || null,
    domain: form.connector === 'samba' ? form.domain || null : null,
  }
  // Empty field: the current password is kept, unless removal was asked.
  if (password) body.password = password
  else if (removePassword.value || !body.username) body.password = ''

  if (editing.value) {
    if (await patch(editing.value, body as Partial<AdminPrinter>)) {
      formOpen.value = false
      await refresh()
    }
    return
  }
  try {
    await api<AdminPrinter>('/api/admin/printers', { method: 'POST', body })
    formOpen.value = false
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Création impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

const toDelete = ref<AdminPrinter | null>(null)

async function remove() {
  const printer = toDelete.value!
  toDelete.value = null
  try {
    await api(`/api/admin/printers/${printer.id}`, { method: 'DELETE' })
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Suppression impossible', description: apiErrorMessage(error), color: 'error' })
  }
}
</script>

<template>
  <UDashboardPanel id="printers">
    <template #header>
      <UDashboardNavbar title="Imprimantes">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton icon="i-lucide-plus" label="Nouvelle imprimante" @click="create" />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UAlert
        icon="i-lucide-info"
        variant="subtle"
        color="neutral"
        title="Connecteurs"
        description="Partage Windows / Samba (smbclient), IPP pour les imprimantes réseau et les files CUPS, ou dossier pour essayer sans imprimante. Les imprimantes actives sont vérifiées toutes les 5 minutes (tableau de bord, état des services)."
      />
      <UTable :data="printers" :columns="columns" :loading="status === 'pending'" empty="Aucune imprimante : ajoutez-en une." />

      <UModal v-model:open="formOpen" :title="editing ? `Modifier ${editing.name}` : 'Nouvelle imprimante'" :ui="{ content: 'max-w-2xl' }">
        <template #body>
          <form id="printer-form" class="grid gap-3 sm:grid-cols-2" @submit.prevent="submit">
            <UFormField label="Nom" required>
              <UInput v-model="form.name" class="w-full" placeholder="Laser 2e étage" />
            </UFormField>
            <UFormField label="Emplacement">
              <UInput v-model="form.location" class="w-full" placeholder="2e étage, open space" />
            </UFormField>
            <UFormField label="Description" class="sm:col-span-2">
              <UTextarea v-model="form.description" class="w-full" :rows="2" />
            </UFormField>
            <UFormField label="Connecteur" required class="sm:col-span-2" :help="connector.help">
              <USelect v-model="form.connector" :items="connectorItems" class="w-full" />
            </UFormField>
            <UFormField :label="form.connector === 'folder' ? 'Dossier' : 'Adresse'" required class="sm:col-span-2">
              <UInput v-model="form.uri" class="w-full font-mono" :placeholder="connector.placeholder" />
            </UFormField>
            <template v-if="form.connector !== 'folder'">
              <UFormField label="Utilisateur" :help="form.connector === 'samba' ? 'Vide : connexion anonyme.' : 'Vide : sans authentification.'">
                <UInput v-model="form.username" class="w-full" autocomplete="off" />
              </UFormField>
              <UFormField label="Mot de passe" :help="editing?.hasPassword ? 'Laisser vide pour garder le mot de passe enregistré.' : undefined">
                <UInput v-model="form.password" type="password" class="w-full" autocomplete="new-password" :placeholder="editing?.hasPassword ? '••••••••' : ''" :disabled="removePassword" />
              </UFormField>
              <UFormField v-if="form.connector === 'samba'" label="Domaine ou groupe de travail">
                <UInput v-model="form.domain" class="w-full" placeholder="WORKGROUP" />
              </UFormField>
              <div v-if="editing?.hasPassword" class="flex items-end">
                <UCheckbox v-model="removePassword" label="Supprimer le mot de passe enregistré" />
              </div>
            </template>
            <div class="flex flex-col gap-2 sm:col-span-2">
              <USwitch v-model="form.duplexSupported" label="Recto verso" />
              <USwitch v-model="form.colorSupported" label="Couleur" />
              <USwitch v-model="form.defaultPrinter" label="Imprimante par défaut" />
              <USwitch v-model="form.enabled" label="Active (proposée aux utilisateurs)" />
            </div>
          </form>
        </template>
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton label="Annuler" color="neutral" variant="ghost" @click="formOpen = false" />
            <UButton type="submit" form="printer-form" :label="editing ? 'Enregistrer' : 'Ajouter'" />
          </div>
        </template>
      </UModal>

      <UModal :open="toDelete !== null" :title="`Supprimer ${toDelete?.name} ?`" description="Les impressions passées restent dans l’historique ; celles en attente échoueront." @update:open="(value: boolean) => { if (!value) toDelete = null }">
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
