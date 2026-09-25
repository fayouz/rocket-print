<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { PrintJob, PrintJobStatus } from '~/types/print'

const appName = useAppConfig().rocket.name
const route = useRoute()
const api = useApi()
const toast = useToast()
const auth = useAuth()
const config = useRuntimeConfig()
const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')
const UTooltip = resolveComponent('UTooltip')

// Administrators see every job with ?all=1 ("Toutes les impressions").
const all = computed(() => auth.isAdmin.value && route.query.all === '1')
useHead({ title: () => `${all.value ? 'Toutes les impressions' : 'Mes impressions'} · ${appName}` })

const search = ref('')
const statusFilter = ref<PrintJobStatus | 'all'>('all')
const statusItems = [{ label: 'Tous les statuts', value: 'all' }, ...Object.entries(PRINT_JOB_STATUS).map(([value, s]) => ({ label: s.label, value }))]
const searchDebounced = ref('')
let debounce: ReturnType<typeof setTimeout> | undefined
watch(search, (value) => {
  clearTimeout(debounce)
  debounce = setTimeout(() => (searchDebounced.value = value), 300)
})

const { data: jobs, status, refresh } = await useAsyncData('print-jobs', () => api<PrintJob[]>('/api/print-jobs', {
  query: {
    'itemsPerPage': 100,
    'all': all.value ? 1 : undefined,
    'status': statusFilter.value === 'all' ? undefined : statusFilter.value,
    'title': searchDebounced.value || undefined,
    'order[createdAt]': 'desc',
  },
}), { default: () => [], watch: [all, statusFilter, searchDebounced] })

// While jobs are waiting or printing, follow them.
const active = computed(() => jobs.value.some(job => job.status === 'queued' || job.status === 'printing'))
let timer: ReturnType<typeof setInterval> | undefined
onMounted(() => {
  timer = setInterval(() => {
    if (active.value && !document.hidden) refresh()
  }, 3000)
})
onBeforeUnmount(() => {
  clearInterval(timer)
  clearTimeout(debounce)
})

async function act(job: PrintJob, action: 'cancel' | 'retry') {
  try {
    Object.assign(job, await api<PrintJob>(`/api/print-jobs/${job.id}/${action}`, { method: 'POST' }))
    toast.add({ title: action === 'cancel' ? 'Impression annulée' : 'Document renvoyé à l’imprimante', color: 'success' })
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Action impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

async function open(job: PrintJob) {
  try {
    const blob = await api<Blob>(`/api/print-jobs/${job.id}/content`, { responseType: 'blob' })
    window.open(URL.createObjectURL(blob), '_blank', 'noopener')
  }
  catch (error) {
    toast.add({ title: 'Document indisponible', description: apiErrorMessage(error), color: 'error' })
  }
}

const columns = computed<TableColumn<PrintJob>[]>(() => [
  {
    accessorKey: 'title',
    header: 'Document',
    cell: ({ row }) => h('div', { class: 'min-w-0' }, [
      h('p', { class: 'truncate font-medium text-highlighted' }, row.original.title),
      h('p', { class: 'text-xs text-muted' }, [formatSize(row.original.size), row.original.applicationName ? ` · via ${row.original.applicationName}` : ''].join('')),
    ]),
  },
  ...(all.value ? [{ accessorKey: 'ownerEmail', header: 'Utilisateur' } as TableColumn<PrintJob>] : []),
  { accessorKey: 'printerName', header: 'Imprimante' },
  {
    id: 'options',
    header: 'Options',
    cell: ({ row }) => [
      `${row.original.copies} ex.`,
      row.original.duplex ? 'recto verso' : null,
      row.original.color ? 'couleur' : null,
    ].filter(Boolean).join(' · '),
  },
  {
    accessorKey: 'status',
    header: 'Statut',
    cell: ({ row }) => {
      const s = PRINT_JOB_STATUS[row.original.status]
      const badge = h(UBadge, { label: s.label, color: s.color, icon: s.icon, variant: 'subtle', class: row.original.status === 'printing' ? '[&>span]:animate-spin' : '' })
      const detail = row.original.error ?? (row.original.attempts > 1 ? `${row.original.attempts} tentatives` : null)
      return detail ? h(UTooltip, { text: detail }, () => badge) : badge
    },
  },
  { accessorKey: 'createdAt', header: 'Envoyé', cell: ({ row }) => h('span', { title: formatDate(row.original.createdAt) }, timeAgo(row.original.createdAt)) },
  {
    id: 'actions',
    cell: ({ row }) => h('div', { class: 'flex justify-end gap-1' }, [
      !row.original.contentPurged && h(UButton, { 'icon': 'i-lucide-eye', 'color': 'neutral', 'variant': 'ghost', 'aria-label': 'Voir le document', 'onClick': () => open(row.original) }),
      row.original.retryable && h(UButton, { 'icon': 'i-lucide-rotate-cw', 'color': 'neutral', 'variant': 'ghost', 'aria-label': 'Réimprimer', 'onClick': () => act(row.original, 'retry') }),
      row.original.cancellable && h(UButton, { 'icon': 'i-lucide-x', 'color': 'error', 'variant': 'ghost', 'aria-label': 'Annuler', 'onClick': () => act(row.original, 'cancel') }),
    ]),
  },
])

const apiBase = computed(() => config.public.apiBase || useRequestURL().origin)
</script>

<template>
  <UDashboardPanel id="jobs">
    <template #header>
      <UDashboardNavbar :title="all ? 'Toutes les impressions' : 'Mes impressions'">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton icon="i-lucide-printer" label="Imprimer" to="/print" />
        </template>
      </UDashboardNavbar>
      <UDashboardToolbar>
        <div class="flex w-full flex-wrap items-center gap-2">
          <UInput v-model="search" icon="i-lucide-search" placeholder="Rechercher un document" class="w-full sm:w-64" />
          <USelect v-model="statusFilter" :items="statusItems" class="w-44" />
          <UButton v-if="auth.isAdmin.value" :to="all ? '/jobs' : '/jobs?all=1'" :label="all ? 'Mes impressions' : 'Toutes les impressions'" color="neutral" variant="ghost" class="ml-auto" />
        </div>
      </UDashboardToolbar>
    </template>

    <template #body>
      <UTable :data="jobs" :columns="columns" :loading="status === 'pending'" empty="Aucune impression." />
      <p class="text-xs text-muted">
        Les documents sont conservés quelques jours après l’impression, puis supprimés. Vos applications impriment via
        <code>POST {{ apiBase }}/api/print-jobs</code> (voir la documentation de l’API).
      </p>
    </template>
  </UDashboardPanel>
</template>
