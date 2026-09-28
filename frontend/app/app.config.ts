/**
 * Identity of the application and its own menu entries: the rest of the interface (layout, dashboard,
 * administration pages) is shared by every Rocket application.
 */
export default defineAppConfig({
  ui: {
    colors: {
      primary: 'sky',
      neutral: 'zinc',
    },
  },
  rocket: {
    id: 'cast',
    name: 'Rocket Cast',
    icon: 'i-lucide-cast',
    // Login page subtitle.
    tagline: 'Diffusez vos playlists sur les écrans, TV et tablettes de vos lieux.',
    // Kiosk (/s/<token>) and pairing (/pair) pages: opened by the screens, without account.
    publicPaths: ['/s/', '/pair'],
    // Main menu: the domain pages ("label" entries start a group).
    navigation: [
      { label: 'Affichage', type: 'label' },
      { label: 'Écrans', icon: 'i-lucide-monitor', to: '/screens' },
      { label: 'Playlists', icon: 'i-lucide-list-video', to: '/playlists' },
      { label: 'Sources', icon: 'i-lucide-plug', to: '/sources' },
    ] as { label: string, icon?: string, to?: string, type?: 'label', exact?: boolean, exactQuery?: boolean, admin?: boolean }[],
    // Extra entries of the Administration menu.
    adminNavigation: [] as { label: string, icon: string, to: string, exactQuery?: boolean }[],
    // "Services & raccourcis" of the dashboard, besides the documentation, changelog and API.
    shortcuts: [
      { label: 'Appairer un écran', description: 'Ouvrez /pair sur la TV, puis saisissez le code affiché.', icon: 'i-lucide-link', to: '/screens?pair=1' },
    ] as { label: string, description: string, icon: string, to: string, admin?: boolean }[],
    // Hero banner of the dashboard: one quote per day.
    quotes: [
      ['Une image vaut mille mots.', 'Proverbe'],
      ['La simplicité est la sophistication suprême.', 'Léonard de Vinci'],
      ['Ce qui se conçoit bien s’énonce clairement.', 'Nicolas Boileau'],
    ] as [string, string][],
  },
})
