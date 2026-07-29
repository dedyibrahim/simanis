export type ThemeMode = 'light' | 'dark'
export type AccentTheme = 'ocean' | 'royal' | 'emerald' | 'sunset' | 'rose' | 'graphite'

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
    value: 'ocean',
    name: 'Ocean Blue',
    description: 'Biru kantor yang bersih dan formal.',
    swatch: 'linear-gradient(135deg, #2563eb, #4f46e5)',
  },
  {
    value: 'royal',
    name: 'Royal Violet',
    description: 'Ungu biru, terasa premium.',
    swatch: 'linear-gradient(135deg, #7c3aed, #2563eb)',
  },
  {
    value: 'emerald',
    name: 'Emerald Mint',
    description: 'Hijau segar, adem untuk kerja lama.',
    swatch: 'linear-gradient(135deg, #059669, #0d9488)',
  },
  {
    value: 'sunset',
    name: 'Sunset Coral',
    description: 'Oranye coral, hangat dan hidup.',
    swatch: 'linear-gradient(135deg, #ea580c, #e11d48)',
  },
  {
    value: 'rose',
    name: 'Rose Berry',
    description: 'Merah muda elegan, tidak terlalu manis.',
    swatch: 'linear-gradient(135deg, #db2777, #9333ea)',
  },
  {
    value: 'graphite',
    name: 'Graphite Gold',
    description: 'Netral gelap dengan aksen emas.',
    swatch: 'linear-gradient(135deg, #334155, #ca8a04)',
  },
]

const isThemeMode = (value: string): value is ThemeMode =>
  value === 'light' || value === 'dark'

const isAccentTheme = (value: string): value is AccentTheme =>
  accentThemeOptions.some(option => option.value === value)

export function useThemeMode() {
  const theme = useState<ThemeMode>('theme-mode', () => 'light')
  const accentTheme = useState<AccentTheme>('accent-theme', () => 'ocean')

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

  const toggleTheme = () => {
    applyTheme(theme.value === 'dark' ? 'light' : 'dark')
  }

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
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches
        applyTheme(prefersDark ? 'dark' : 'light')
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
