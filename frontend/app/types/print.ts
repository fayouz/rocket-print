import type { Tracked } from '#rocket/types/api'

export type PrinterConnector = 'samba' | 'ipp' | 'folder'

/** A printer as users see it (GET /api/printers). */
export interface Printer {
  id: string
  name: string
  description: string | null
  location: string | null
  connectorLabel: string
  colorSupported: boolean
  duplexSupported: boolean
  defaultPrinter: boolean
  enabled: boolean
}

/** A printer with its connection settings (GET /api/admin/printers, administrators). */
export interface AdminPrinter extends Printer, Tracked {
  connector: PrinterConnector
  uri: string
  username: string | null
  domain: string | null
  hasPassword: boolean
}

export type PrintJobStatus = 'queued' | 'printing' | 'printed' | 'failed' | 'cancelled'

export interface PrintJob extends Tracked {
  id: string
  title: string
  mimeType: string
  size: number
  printerId: string | null
  printerName: string
  ownerEmail: string
  applicationName: string | null
  copies: number
  duplex: boolean
  color: boolean
  status: PrintJobStatus
  attempts: number
  error: string | null
  externalId: string | null
  printedAt: string | null
  contentPurged: boolean
  cancellable: boolean
  retryable: boolean
}

export interface PrintSettings {
  maxFileSize: number
  formats: Record<string, string>
}
