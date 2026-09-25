import type { PrintJobStatus } from '~/types/api'

export const PRINT_JOB_STATUS: Record<PrintJobStatus, { label: string, color: 'neutral' | 'info' | 'success' | 'error' | 'warning', icon: string }> = {
  queued: { label: 'En attente', color: 'neutral', icon: 'i-lucide-clock' },
  printing: { label: 'En cours', color: 'info', icon: 'i-lucide-loader-circle' },
  printed: { label: 'Imprimé', color: 'success', icon: 'i-lucide-circle-check' },
  failed: { label: 'Échec', color: 'error', icon: 'i-lucide-circle-x' },
  cancelled: { label: 'Annulé', color: 'neutral', icon: 'i-lucide-ban' },
}

/** Placeholder and help of the address field, by connector. */
export const PRINTER_CONNECTORS = {
  samba: {
    label: 'Partage Windows / Samba',
    placeholder: '//serveur-impression/imprimante',
    help: 'Imprimante partagée par un serveur Windows ou Samba (smbclient). Le document est transmis tel quel : l’imprimante doit comprendre son format (PDF, PostScript ou PCL).',
  },
  ipp: {
    label: 'IPP / CUPS',
    placeholder: 'ipp://imprimante.local/ipp/print',
    help: 'Imprimante réseau (IPP Everywhere, AirPrint) ou file CUPS : ipp://serveur:631/printers/file. Recto verso et couleur sont transmis à l’imprimante.',
  },
  folder: {
    label: 'Dossier (test)',
    placeholder: 'tests',
    help: 'Écrit les documents dans un sous-dossier de PRINT_FOLDER_ROOT, avec leurs options dans un fichier .json : pour essayer sans imprimante ou archiver.',
  },
} as const
