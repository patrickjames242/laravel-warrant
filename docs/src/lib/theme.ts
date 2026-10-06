import { useSyncExternalStore } from 'react'

export type Theme = 'light' | 'dark'

/** What the reader has asked for: one theme always, or whichever their system uses. */
export type ThemePreference = Theme | 'system'

/**
 * Where the reader's choice is kept. The script in index.html reads the same
 * key to settle the theme before the first paint, so the two must agree.
 */
const STORAGE = 'docs-theme'

const SYSTEM_LIGHT = '(prefers-color-scheme: light)'

const THEME_COLOR: Record<Theme, string> = { light: '#f9f3e8', dark: '#120e0c' }

/** Everything showing the preference, told when this tab changes it. */
const listeners = new Set<() => void>()

/** The choice made in this tab, kept for when storage cannot be read or written. */
let unsaved: ThemePreference = 'system'

function storedPreference(): ThemePreference {
  try {
    const value = localStorage.getItem(STORAGE)
    return value === 'light' || value === 'dark' ? value : 'system'
  } catch {
    return unsaved
  }
}

function resolve(preference: ThemePreference): Theme {
  if (preference !== 'system') return preference
  return window.matchMedia(SYSTEM_LIGHT).matches ? 'light' : 'dark'
}

function apply(theme: Theme): void {
  document.documentElement.dataset.theme = theme
  document.querySelector<HTMLMetaElement>('meta[name="theme-color"]')?.setAttribute('content', THEME_COLOR[theme])
}

function currentTheme(): Theme {
  return document.documentElement.dataset.theme === 'light' ? 'light' : 'dark'
}

/**
 * Follows the theme on the root element and the preference behind it. While
 * the preference is the system's, changing the system's changes the page, and
 * a choice made in another tab is taken up here too.
 */
function subscribe(onChange: () => void): () => void {
  const observer = new MutationObserver(onChange)
  observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] })

  const media = window.matchMedia(SYSTEM_LIGHT)
  const followSystem = () => {
    if (storedPreference() === 'system') apply(resolve('system'))
  }
  media.addEventListener('change', followSystem)

  const followOtherTabs = (event: StorageEvent) => {
    if (event.key !== STORAGE) return
    apply(resolve(storedPreference()))
    onChange()
  }
  window.addEventListener('storage', followOtherTabs)

  listeners.add(onChange)

  return () => {
    observer.disconnect()
    media.removeEventListener('change', followSystem)
    window.removeEventListener('storage', followOtherTabs)
    listeners.delete(onChange)
  }
}

/** The theme the page is drawn in, the reader's preference behind it, and a way to change that. */
export function useTheme(): {
  theme: Theme
  preference: ThemePreference
  setPreference: (preference: ThemePreference) => void
} {
  const theme = useSyncExternalStore(subscribe, currentTheme, () => 'dark' as const)
  const preference = useSyncExternalStore(subscribe, storedPreference, () => 'system' as const)

  const setPreference = (next: ThemePreference) => {
    unsaved = next
    try {
      localStorage.setItem(STORAGE, next)
    } catch {
      // Without storage the choice lasts until the page is reloaded.
    }
    apply(resolve(next))
    for (const listener of listeners) listener()
  }

  return { theme, preference, setPreference }
}
