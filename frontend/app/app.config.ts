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
    id: 'print',
    name: 'Rocket Print',
    icon: 'i-lucide-printer',
    // Login page subtitle.
    tagline: 'Imprimez sur les imprimantes de l’entreprise, depuis le navigateur ou vos applications.',
    // Main menu: the domain pages ("label" entries start a group).
    navigation: [
      { label: 'Impression', type: 'label' },
      { label: 'Imprimer', icon: 'i-lucide-printer', to: '/print' },
      { label: 'Mes impressions', icon: 'i-lucide-list', to: '/jobs', exactQuery: true },
    ] as { label: string, icon?: string, to?: string, type?: 'label', exact?: boolean, exactQuery?: boolean, admin?: boolean }[],
    // Extra entries of the Administration menu.
    adminNavigation: [
      { label: 'Imprimantes', icon: 'i-lucide-printer-check', to: '/printers' },
      { label: 'Toutes les impressions', icon: 'i-lucide-list-checks', to: '/jobs?all=1', exactQuery: true },
    ] as { label: string, icon: string, to: string, exactQuery?: boolean }[],
    // "Services & raccourcis" of the dashboard, besides the documentation, changelog and API.
    shortcuts: [] as { label: string, description: string, icon: string, to: string, admin?: boolean }[],
    // Hero banner of the dashboard: one quote per day.
    quotes: [
      ['Les paroles s’envolent, les écrits restent.', 'Proverbe latin'],
      ['La simplicité est la sophistication suprême.', 'Léonard de Vinci'],
      ['Ce qui se conçoit bien s’énonce clairement.', 'Nicolas Boileau'],
    ] as [string, string][],
  },
})
