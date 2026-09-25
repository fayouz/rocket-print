<script setup lang="ts">
import type { Printer, PrintJob, PrintSettings } from '~/types/print'

const appName = useAppConfig().rocket.name
useHead({ title: `Imprimer · ${appName}` })

const api = useApi()
const toast = useToast()

const { data: printers, status } = await useAsyncData('print-printers', () => api<Printer[]>('/api/printers', { query: { itemsPerPage: 200 } }), { default: () => [] })
const { data: settings } = await useAsyncData('print-settings', () => api<PrintSettings>('/api/print-jobs/settings'))

const file = ref<File | null>(null)
const form = reactive({ printer: '', copies: 1, duplex: false, color: false })
const sending = ref(false)
const dragging = ref(false)
const input = useTemplateRef<HTMLInputElement>('input')

watch(printers, (list) => {
  if (!form.printer || !list.some(p => p.id === form.printer)) {
    form.printer = (list.find(p => p.defaultPrinter) ?? list[0])?.id ?? ''
  }
}, { immediate: true })

const printer = computed(() => printers.value.find(p => p.id === form.printer) ?? null)
const printerItems = computed(() => printers.value.map(p => ({ label: p.name, value: p.id, description: p.location ?? p.connectorLabel })))
const formats = computed(() => Object.values(settings.value?.formats ?? {}).join(', '))
const accept = computed(() => [...Object.keys(settings.value?.formats ?? {}), '.pcl', '.prn', '.ps'].join(','))
const tooBig = computed(() => !!file.value && !!settings.value && file.value.size > settings.value.maxFileSize)

function pick(files: FileList | null | undefined) {
  if (files?.[0]) file.value = files[0]
}

function onDrop(event: DragEvent) {
  dragging.value = false
  pick(event.dataTransfer?.files)
}

async function submit() {
  if (!file.value || !printer.value) return
  sending.value = true
  try {
    const body = new FormData()
    body.append('file', file.value)
    body.append('printer', printer.value.id)
    body.append('copies', String(form.copies))
    body.append('duplex', form.duplex && printer.value.duplexSupported ? '1' : '0')
    body.append('color', form.color && printer.value.colorSupported ? '1' : '0')
    const job = await api<PrintJob>('/api/print-jobs', { method: 'POST', body })
    toast.add({ title: 'Document envoyé', description: `« ${job.title} » est dans la file de ${job.printerName}.`, color: 'success', icon: 'i-lucide-printer' })
    file.value = null
    await navigateTo('/jobs')
  }
  catch (error) {
    toast.add({ title: 'Impression impossible', description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    sending.value = false
  }
}
</script>

<template>
  <UDashboardPanel id="print">
    <template #header>
      <UDashboardNavbar title="Imprimer">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton icon="i-lucide-list" label="Mes impressions" color="neutral" variant="outline" to="/jobs" />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="mx-auto flex w-full max-w-3xl flex-col gap-4">
        <UAlert
          v-if="status !== 'pending' && !printers.length"
          icon="i-lucide-printer-x"
          color="warning"
          variant="subtle"
          title="Aucune imprimante disponible"
          description="Un administrateur doit d’abord déclarer les imprimantes (Administration → Imprimantes)."
        />

        <form class="flex flex-col gap-4" @submit.prevent="submit">
          <div
            data-testid="dropzone"
            class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed p-8 text-center transition"
            :class="dragging ? 'border-primary bg-primary/5' : 'border-default hover:border-primary/60'"
            role="button"
            tabindex="0"
            @click="input?.click()"
            @keydown.enter.prevent="input?.click()"
            @dragover.prevent="dragging = true"
            @dragleave.prevent="dragging = false"
            @drop.prevent="onDrop"
          >
            <UIcon :name="file ? 'i-lucide-file-check' : 'i-lucide-file-up'" class="size-10 text-primary" />
            <template v-if="file">
              <p class="font-medium text-highlighted">
                {{ file.name }}
              </p>
              <p class="text-sm" :class="tooBig ? 'text-error' : 'text-muted'">
                {{ formatSize(file.size) }}<template v-if="tooBig">
                  · dépasse la taille maximale ({{ formatSize(settings!.maxFileSize) }})
                </template>
              </p>
            </template>
            <template v-else>
              <p class="font-medium text-highlighted">
                Déposez un document ici, ou cliquez pour le choisir
              </p>
              <p class="text-sm text-muted">
                Formats : {{ formats || 'PDF' }}<template v-if="settings">
                  · {{ formatSize(settings.maxFileSize) }} maximum
                </template>
              </p>
            </template>
            <input ref="input" type="file" class="hidden" :accept="accept" @change="pick(($event.target as HTMLInputElement).files)">
          </div>

          <UCard>
            <div class="grid gap-4 sm:grid-cols-2">
              <UFormField label="Imprimante" class="sm:col-span-2" required>
                <USelect v-model="form.printer" :items="printerItems" class="w-full" placeholder="Choisir une imprimante" />
                <template v-if="printer" #help>
                  {{ [printer.location, printer.description].filter(Boolean).join(' · ') || printer.connectorLabel }}
                </template>
              </UFormField>
              <UFormField label="Exemplaires">
                <UInputNumber v-model="form.copies" :min="1" :max="99" class="w-full" />
              </UFormField>
              <div class="flex flex-col justify-end gap-2">
                <USwitch v-model="form.duplex" label="Recto verso" :disabled="!printer?.duplexSupported" />
                <USwitch v-model="form.color" label="Couleur" :disabled="!printer?.colorSupported" />
              </div>
            </div>
          </UCard>

          <div class="flex justify-end">
            <UButton type="submit" icon="i-lucide-printer" label="Imprimer" size="lg" :loading="sending" :disabled="!file || !printer || tooBig" />
          </div>
        </form>
      </div>
    </template>
  </UDashboardPanel>
</template>
