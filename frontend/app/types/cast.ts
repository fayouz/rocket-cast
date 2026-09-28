export type PanelType = 'welcome' | 'clock' | 'weather' | 'wifi' | 'checkout' | 'image' | 'richtext' | 'qrcode' | 'source'

export interface PanelSchedule {
  /** ISO days, 1 = Monday. */
  days: number[]
  from: string
  until: string
}

export interface Panel {
  id: string
  type: PanelType
  duration: number
  enabled: boolean
  schedule: PanelSchedule | null
  settings: Record<string, unknown>
}

interface Tracking {
  createdAt: string | null
  updatedAt: string | null
  createdBy: string | null
  updatedBy: string | null
}

export interface Playlist extends Tracking {
  id: string
  name: string
  description: string | null
  accent: string
  theme: 'dark' | 'light'
  panelCount: number
  /** Seconds of one loop (enabled panels). */
  duration: number
  screenCount: number
  panels?: Panel[]
}

export interface Screen extends Tracking {
  id: string
  name: string
  location: string | null
  /** Rocket Place place the screen stands in (optional). */
  placeId: string | null
  /** Cached name of that place. */
  placeName: string | null
  orientation: 'landscape' | 'portrait'
  timezone: string
  locale: string
  enabled: boolean
  playlist: { id: string, name: string } | null
  hasToken: boolean
  tokenHint: string | null
  lastSeenAt: string | null
  online: boolean
  lastUserAgent: string | null
}

/** Returned once, when the link is created or rotated. */
export interface ScreenLink {
  token: string
  kioskPath: string
  kioskUrl: string
}

export interface SourceField {
  key: string
  label: string
  type: 'text' | 'url' | 'email' | 'password' | 'textarea' | 'select'
  secret?: boolean
  required?: boolean
  help?: string
  placeholder?: string
  options?: { label: string, value: string }[]
}

export interface SourceType {
  id: string
  name: string
  icon: string
  description: string
  capabilities: { id: string, label: string }[]
  fields: SourceField[]
}

export interface Source extends Tracking {
  id: string
  name: string
  type: string
  typeName: string
  icon: string
  capabilities: { id: string, label: string }[]
  enabled: boolean
  refreshSeconds: number
  fetchedAt: string | null
  attemptedAt: string | null
  lastError: string | null
  /** Administrators only. */
  config?: Record<string, string>
  secrets?: Record<string, boolean>
  secretsReadable?: boolean
}

/** A panel as the kiosk receives it. */
export interface KioskPanel {
  id: string
  type: PanelType
  duration: number
  settings: Record<string, unknown>
  data?: Record<string, unknown> | null
  /** Preview only. */
  activeNow?: boolean
  schedule?: PanelSchedule | null
}

export interface KioskPayload {
  screen?: { name: string, orientation: 'landscape' | 'portrait', timezone: string, locale: string, enabled: boolean }
  playlist?: { name: string, accent: string, theme: 'dark' | 'light' } | null
  panels: KioskPanel[]
  generatedAt: string
  reloadAt: string
  pollSeconds: number
  version: string
}
