import type { Panel, PanelSchedule, PanelType } from '~/types/cast'

export interface PanelField {
  key: string
  label: string
  type: 'text' | 'textarea' | 'number' | 'switch' | 'select' | 'time' | 'url'
  placeholder?: string
  help?: string
  options?: { label: string, value: string }[]
  step?: number
}

/** Panel types: label, icon, default settings and form fields (the "source" panel has its own form). */
export const PANEL_TYPES: Record<PanelType, { label: string, icon: string, description: string, defaults: Record<string, unknown>, fields: PanelField[] }> = {
  welcome: {
    label: 'Message d’accueil', icon: 'i-lucide-hand', description: 'Un titre et un message.',
    defaults: { title: 'Bienvenue', text: '' },
    fields: [{ key: 'title', label: 'Titre', type: 'text' }, { key: 'text', label: 'Message', type: 'textarea' }],
  },
  clock: {
    label: 'Horloge', icon: 'i-lucide-clock', description: 'L’heure et la date, dans le fuseau de l’écran.',
    defaults: { label: '', showDate: true, showSeconds: false },
    fields: [{ key: 'label', label: 'Libellé', type: 'text', placeholder: 'Paris' }, { key: 'showDate', label: 'Afficher la date', type: 'switch' }, { key: 'showSeconds', label: 'Afficher les secondes', type: 'switch' }],
  },
  weather: {
    label: 'Météo', icon: 'i-lucide-cloud-sun', description: 'Prévisions Open-Meteo, lues par l’écran lui-même.',
    defaults: { label: 'Météo', latitude: 48.8566, longitude: 2.3522, days: 3 },
    fields: [
      { key: 'label', label: 'Titre', type: 'text' },
      { key: 'latitude', label: 'Latitude', type: 'number', step: 0.0001 },
      { key: 'longitude', label: 'Longitude', type: 'number', step: 0.0001 },
      { key: 'days', label: 'Jours de prévision', type: 'number', step: 1 },
    ],
  },
  wifi: {
    label: 'Wi-Fi', icon: 'i-lucide-wifi', description: 'Réseau, mot de passe et QR code de connexion.',
    defaults: { ssid: '', password: '', security: 'WPA', showQr: true },
    fields: [
      { key: 'ssid', label: 'Réseau (SSID)', type: 'text' },
      { key: 'password', label: 'Mot de passe', type: 'text' },
      { key: 'security', label: 'Sécurité', type: 'select', options: [{ label: 'WPA/WPA2/WPA3', value: 'WPA' }, { label: 'WEP', value: 'WEP' }, { label: 'Ouvert', value: 'nopass' }] },
      { key: 'showQr', label: 'QR code de connexion', type: 'switch' },
    ],
  },
  checkout: {
    label: 'Départ', icon: 'i-lucide-door-open', description: 'Heure de départ et consignes.',
    defaults: { time: '11:00', text: '' },
    fields: [{ key: 'time', label: 'Heure de départ', type: 'time' }, { key: 'text', label: 'Consignes', type: 'textarea' }],
  },
  image: {
    label: 'Image', icon: 'i-lucide-image', description: 'Une image https:// ou un lien Rocket Cloud.',
    defaults: { url: '', fit: 'cover', caption: '' },
    fields: [
      { key: 'url', label: 'Adresse de l’image', type: 'url', placeholder: 'https://…', help: 'Adresse https:// ou lien de partage Rocket Cloud (fichier image).' },
      { key: 'fit', label: 'Cadrage', type: 'select', options: [{ label: 'Remplir l’écran', value: 'cover' }, { label: 'Image entière', value: 'contain' }] },
      { key: 'caption', label: 'Légende', type: 'text' },
    ],
  },
  richtext: {
    label: 'Texte', icon: 'i-lucide-text', description: 'Texte mis en forme : **gras**, *italique*, listes « - ».',
    defaults: { title: '', text: '' },
    fields: [{ key: 'title', label: 'Titre', type: 'text' }, { key: 'text', label: 'Texte', type: 'textarea', help: '**gras**, *italique*, lignes « - » pour une liste, ligne vide entre deux paragraphes.' }],
  },
  qrcode: {
    label: 'QR code', icon: 'i-lucide-qr-code', description: 'Un lien ou un texte à scanner.',
    defaults: { value: '', title: '', caption: '' },
    fields: [{ key: 'value', label: 'Contenu (lien, texte)', type: 'text' }, { key: 'title', label: 'Titre', type: 'text' }, { key: 'caption', label: 'Légende', type: 'text' }],
  },
  source: {
    label: 'Source', icon: 'i-lucide-plug', description: 'Des données d’une intégration : Rocket PMS, Rocket Place, Web JSON…',
    defaults: { sourceId: '', view: '', title: '' },
    fields: [],
  },
}

export const DAYS = [
  { value: 1, label: 'Lun' }, { value: 2, label: 'Mar' }, { value: 3, label: 'Mer' }, { value: 4, label: 'Jeu' },
  { value: 5, label: 'Ven' }, { value: 6, label: 'Sam' }, { value: 7, label: 'Dim' },
]

export function newPanel(type: PanelType): Panel {
  return { id: Math.random().toString(36).slice(2, 12), type, duration: 15, enabled: true, schedule: null, settings: structuredClone(PANEL_TYPES[type].defaults) }
}

export function scheduleLabel(schedule: PanelSchedule | null): string {
  if (!schedule) return 'Toujours'
  const days = schedule.days.length === 7 ? 'Tous les jours' : schedule.days.map(d => DAYS[d - 1]?.label).join(', ')
  const hours = schedule.from === '00:00' && schedule.until === '24:00' ? 'toute la journée' : `${schedule.from} → ${schedule.until}`
  return `${days}, ${hours}`
}

export function formatDuration(seconds: number): string {
  if (seconds < 60) return `${seconds} s`
  const m = Math.floor(seconds / 60)
  const s = seconds % 60
  return s ? `${m} min ${s} s` : `${m} min`
}

function escapeHtml(text: string): string {
  return text.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#39;' })[c]!)
}

function inline(text: string): string {
  return escapeHtml(text)
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/\*(.+?)\*/g, '<em>$1</em>')
}

/** Small, safe Markdown subset (text escaped first): paragraphs, line breaks, **bold**, *italic*, "- " lists. */
export function renderRichText(text: string): string {
  return text.trim().split(/\n\s*\n/).map((block) => {
    const lines = block.split('\n')
    if (lines.every(l => /^\s*[-*]\s+/.test(l))) {
      return `<ul>${lines.map(l => `<li>${inline(l.replace(/^\s*[-*]\s+/, ''))}</li>`).join('')}</ul>`
    }
    return `<p>${lines.map(inline).join('<br>')}</p>`
  }).join('')
}

/** "WIFI:" payload read by phone cameras. */
export function wifiQrPayload(ssid: string, password: string, security: string): string {
  const esc = (v: string) => v.replace(/([\\;,:"])/g, '\\$1')
  return security === 'nopass' ? `WIFI:T:nopass;S:${esc(ssid)};;` : `WIFI:T:${security};S:${esc(ssid)};P:${esc(password)};;`
}

/** WMO weather codes (Open-Meteo) → label and icon. */
export function weatherCode(code: number): { label: string, icon: string } {
  if (code === 0) return { label: 'Ensoleillé', icon: 'i-lucide-sun' }
  if (code <= 2) return { label: 'Éclaircies', icon: 'i-lucide-cloud-sun' }
  if (code === 3) return { label: 'Couvert', icon: 'i-lucide-cloud' }
  if (code <= 48) return { label: 'Brouillard', icon: 'i-lucide-cloud-fog' }
  if (code <= 57) return { label: 'Bruine', icon: 'i-lucide-cloud-drizzle' }
  if (code <= 67) return { label: 'Pluie', icon: 'i-lucide-cloud-rain' }
  if (code <= 77) return { label: 'Neige', icon: 'i-lucide-cloud-snow' }
  if (code <= 82) return { label: 'Averses', icon: 'i-lucide-cloud-rain-wind' }
  if (code <= 86) return { label: 'Averses de neige', icon: 'i-lucide-cloud-snow' }
  return { label: 'Orages', icon: 'i-lucide-cloud-lightning' }
}

export function relativeTime(iso: string | null): string {
  if (!iso) return 'jamais'
  const seconds = Math.round((Date.now() - new Date(iso).getTime()) / 1000)
  if (seconds < 60) return 'à l’instant'
  if (seconds < 3600) return `il y a ${Math.floor(seconds / 60)} min`
  if (seconds < 86400) return `il y a ${Math.floor(seconds / 3600)} h`
  return new Date(iso).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
}

export const TIMEZONES = ['Europe/Paris', 'Europe/Brussels', 'Europe/Zurich', 'Europe/Luxembourg', 'Europe/London', 'Europe/Madrid', 'Europe/Rome', 'Europe/Berlin', 'America/Montreal', 'America/New_York', 'America/Martinique', 'America/Guadeloupe', 'Indian/Reunion', 'Pacific/Tahiti', 'Africa/Casablanca', 'UTC']
