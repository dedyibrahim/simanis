export type ThemeMode = 'light' | 'dark'

const STORAGE_KEY = 'simanis_theme_mode'

const isThemeMode = (value: string): value is ThemeMode =>
  value === 'light' || value === 'dark'

export function useThemeMode() {
  const theme = useState<ThemeMode>('theme-mode', () => 'light')

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

  const setTheme = (mode: ThemeMode) => {
    applyTheme(mode)
  }

  const toggleTheme = () => {
    applyTheme(theme.value === 'dark' ? 'light' : 'dark')
  }

  const isDark = computed(() => theme.value === 'dark')

  if (import.meta.client) {
    onMounted(() => {
      const saved = window.localStorage.getItem(STORAGE_KEY)
      if (saved && isThemeMode(saved)) {
        applyTheme(saved)
        return
      }

      const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches
      applyTheme(prefersDark ? 'dark' : 'light')
    })
  }

  return {
    theme,
    isDark,
    setTheme,
    toggleTheme,
  }
}
