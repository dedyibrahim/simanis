export type ThemeMode = 'light' | 'dark'
export type AccentTheme =
  | 'simanis-modern'
  | 'emerald'
  | 'sunset'
  | 'violet-pop'
  | 'indigo-glass'
  | 'graphite'
  | 'quantum-neon'
  | 'matrix'
  | 'spectrum-flow'
  | 'royal'
  | 'abstract-motion'
  | 'midnight-glass'
  | 'particle-nexus'
  | 'aqua-ripple'
  | 'aurora-veil'
  | 'neon-bubble'
  | 'meteor-drift'
  | 'crystal-drift'
  | 'prism-mosaic'
  | 'circuit-slate'

const STORAGE_KEY = 'simanis_theme_mode'
const ACCENT_STORAGE_KEY = 'simanis_accent_theme'

type AccentThemeOption = {
  value: AccentTheme
  name: string
  description: string
  swatch: string
}

export const accentThemeOptions: AccentThemeOption[] = [
  {
    value: 'simanis-modern',
    name: 'SIMANIS Modern',
    description: 'Biru khas SIMANIS.',
    swatch: 'linear-gradient(135deg, #4f46e5 0%, #2563eb 45%, #db2777 100%)',
  },
  {
    value: 'emerald',
    name: 'Emerald Green',
    description: 'Hijau segar dan tenang.',
    swatch: 'linear-gradient(135deg, #059669, #22c55e)',
  },
  {
    value: 'sunset',
    name: 'Sunset Orange',
    description: 'Jingga hangat dan tegas.',
    swatch: 'linear-gradient(135deg, #f97316, #fb7185)',
  },
  {
    value: 'violet-pop',
    name: 'Violet Pop',
    description: 'Gelembung pop berwarna.',
    swatch: 'radial-gradient(circle at 25% 25%, #f0abfc 0 13%, transparent 14%), radial-gradient(circle at 72% 65%, #7c3aed 0 20%, transparent 21%), linear-gradient(135deg, #6d28d9, #ec4899)',
  },
  {
    value: 'indigo-glass',
    name: 'Indigo Glass',
    description: 'Panel kaca berlapis.',
    swatch: 'linear-gradient(135deg, #1d4ed8 0 45%, #818cf8 46% 62%, #4338ca 63% 100%)',
  },
  {
    value: 'graphite',
    name: 'Graphite Grid',
    description: 'Garis teknis yang modern.',
    swatch: 'linear-gradient(rgba(148,163,184,.25) 1px, transparent 1px), linear-gradient(90deg, rgba(148,163,184,.25) 1px, transparent 1px), linear-gradient(135deg, #0f172a, #475569)',
  },
  {
    value: 'quantum-neon',
    name: 'Quantum Neon',
    description: 'Jaringan neon digital.',
    swatch: 'linear-gradient(45deg, transparent 42%, #22d3ee 43% 48%, transparent 49%), linear-gradient(135deg, #111827, #7e22ce 45%, #06b6d4)',
  },
  {
    value: 'matrix',
    name: 'Matrix Digital',
    description: 'Grid digital modern.',
    swatch: 'linear-gradient(rgba(34,197,94,.35) 1px, transparent 1px), linear-gradient(90deg, rgba(34,197,94,.35) 1px, transparent 1px), linear-gradient(135deg, #020617, #064e3b)',
  },
  {
    value: 'spectrum-flow',
    name: 'Spectrum Flow',
    description: 'Aliran warna yang lembut.',
    swatch: 'linear-gradient(135deg, #06b6d4 0%, #8b5cf6 45%, #ec4899 100%)',
  },
  {
    value: 'royal',
    name: 'Royal Luxury',
    description: 'Ungu dan emas berkelas.',
    swatch: 'linear-gradient(135deg, #1e1b4b 0 42%, #f59e0b 43% 50%, #7c3aed 51% 100%)',
  },
  {
    value: 'abstract-motion',
    name: 'Abstract Motion',
    description: 'Geometri modern berlapis.',
    swatch: 'linear-gradient(45deg, #0f172a 0 34%, #ec4899 35% 42%, #2563eb 43% 100%)',
  },
  {
    value: 'midnight-glass',
    name: 'Midnight Glass',
    description: 'Kaca malam yang modern.',
    swatch: 'radial-gradient(circle at 72% 25%, #67e8f9 0 6%, transparent 7%), radial-gradient(circle at 25% 75%, #93c5fd 0 8%, transparent 9%), linear-gradient(135deg, #020617, #0f172a)',
  },
  {
    value: 'particle-nexus',
    name: 'Particle Nexus Live',
    description: 'Partikel mengikuti pointer.',
    swatch: 'radial-gradient(circle at 24% 24%, #bfdbfe 0 8%, transparent 9%), radial-gradient(circle at 70% 70%, #60a5fa 0 10%, transparent 11%), linear-gradient(135deg, #1e3a8a, #312e81)',
  },
  {
    value: 'aqua-ripple',
    name: 'Aqua Ripple Live',
    description: 'Riak air merespons klik.',
    swatch: 'radial-gradient(circle at 50% 50%, transparent 0 22%, #22d3ee 23% 28%, transparent 29%), linear-gradient(135deg, #0e7490, #67e8f9)',
  },
  {
    value: 'aurora-veil',
    name: 'Aurora Veil Live',
    description: 'Gelembung aurora besar.',
    swatch: 'radial-gradient(circle at 30% 70%, #67e8f9 0 18%, transparent 19%), radial-gradient(circle at 70% 30%, #a78bfa 0 22%, transparent 23%), linear-gradient(135deg, #111827, #0f766e)',
  },
  {
    value: 'neon-bubble',
    name: 'Neon Bubble Live',
    description: 'Gelembung neon interaktif.',
    swatch: 'radial-gradient(circle at 28% 30%, #f0abfc 0 13%, transparent 14%), radial-gradient(circle at 72% 70%, #a855f7 0 18%, transparent 19%), linear-gradient(135deg, #581c87, #ec4899)',
  },
  {
    value: 'meteor-drift',
    name: 'Meteor Drift Live',
    description: 'Meteor bergerak responsif.',
    swatch: 'linear-gradient(135deg, transparent 35%, #f59e0b 36% 42%, transparent 43%), linear-gradient(135deg, #020617, #0f172a)',
  },
  {
    value: 'crystal-drift',
    name: 'Crystal Drift Live',
    description: 'Kristal cahaya turun perlahan.',
    swatch: 'radial-gradient(circle at 25% 28%, #67e8f9 0 9%, transparent 10%), radial-gradient(circle at 72% 72%, #f0abfc 0 14%, transparent 15%), linear-gradient(135deg, #1e293b, #312e81)',
  },
  {
    value: 'prism-mosaic',
    name: 'Prism Mosaic',
    description: 'Potongan prisma modern.',
    swatch: 'linear-gradient(135deg, #3730a3 0 45%, #ec4899 46% 57%, #38bdf8 58% 100%)',
  },
  {
    value: 'circuit-slate',
    name: 'Circuit Slate Live',
    description: 'Ikon jaringan bergerak lembut.',
    swatch: 'linear-gradient(rgba(14,165,233,.4) 1px, transparent 1px), linear-gradient(90deg, rgba(14,165,233,.4) 1px, transparent 1px), linear-gradient(135deg, #0f172a, #082f49)',
  },
]

const isAccentTheme = (value: string): value is AccentTheme =>
  accentThemeOptions.some(option => option.value === value)

const isThemeMode = (value: string): value is ThemeMode =>
  value === 'light' || value === 'dark'

export function useThemeMode() {
  const theme = useState<ThemeMode>('theme-mode', () => 'light')
  const accentTheme = useState<AccentTheme>('accent-theme', () => 'simanis-modern')

  const applyTheme = (mode: ThemeMode) => {
    theme.value = mode

    if (!import.meta.client) {
      return
    }

    const root = window.document.documentElement
    root.classList.toggle('theme-dark', mode === 'dark')
    root.style.colorScheme = mode
    window.localStorage.setItem(STORAGE_KEY, mode)
  }

  const applyAccentTheme = (value: AccentTheme) => {
    accentTheme.value = value

    if (!import.meta.client) {
      return
    }

    const root = window.document.documentElement
    accentThemeOptions.forEach(option => {
      root.classList.toggle(`theme-accent-${option.value}`, option.value === value)
    })
    window.localStorage.setItem(ACCENT_STORAGE_KEY, value)
  }

  const setTheme = (mode: ThemeMode) => {
    applyTheme(mode)
  }

  const setAccentTheme = (value: AccentTheme) => {
    applyAccentTheme(value)
  }

  const toggleTheme = () => applyTheme(theme.value === 'dark' ? 'light' : 'dark')

  const isDark = computed(() => theme.value === 'dark')
  const activeAccentTheme = computed<AccentThemeOption>(() =>
    accentThemeOptions.find(option => option.value === accentTheme.value) ?? accentThemeOptions[0]!,
  )

  if (import.meta.client) {
    onMounted(() => {
      const saved = window.localStorage.getItem(STORAGE_KEY)
      if (saved && isThemeMode(saved)) {
        applyTheme(saved)
      } else {
        applyTheme(window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
      }

      const savedAccent = window.localStorage.getItem(ACCENT_STORAGE_KEY)
      applyAccentTheme(savedAccent && isAccentTheme(savedAccent) ? savedAccent : accentTheme.value)
    })
  }

  return {
    theme,
    accentTheme,
    activeAccentTheme,
    isDark,
    setTheme,
    setAccentTheme,
    toggleTheme,
    accentThemeOptions,
  }
}
