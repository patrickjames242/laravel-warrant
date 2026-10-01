import { useSyncExternalStore } from 'react'

export type Theme = 'light' | 'dark'

/**
 * Where the reader's choice is kept. The script in index.html reads the same
 * key to settle the theme before the first paint, so the two must agree.
 */
const STORAGE = 'docs-theme'

const SYSTEM_LIGHT = '(prefers-color-scheme: light)'

const THEME_COLOR: Record<Theme, string> = { light: '#faf5f0', dark: '#120e0c' }

function stored(): Theme | null {
  try {
    const value = localStorage.getItem(STORAGE)
    return value === 'light' || value === 'dark' ? value : null
  } catch {
    return null
  }
}

function apply(theme: Theme): void {
  document.documentElement.dataset.theme = theme
  document.querySelector<HTMLMetaElement>('meta[name="theme-color"]')?.setAttribute('content', THEME_COLOR[theme])
}

function current(): Theme {
  return document.documentElement.dataset.theme === 'light' ? 'light' : 'dark'
}

/**
 * Follows the theme on the root element. Until the reader picks one, it also
 * follows their system's, so changing that changes the page.
 */
function subscribe(onChange: () => void): () => void {
  const observer = new MutationObserver(onChange)
  observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] })

  const media = window.matchMedia(SYSTEM_LIGHT)
  const followSystem = () => {
    if (stored() === null) apply(media.matches ? 'light' : 'dark')
  }
  media.addEventListener('change', followSystem)

  return () => {
    observer.disconnect()
    media.removeEventListener('change', followSystem)
  }
}

/** The theme the page is drawn in, and a way to choose the other one. */
export function useTheme(): { theme: Theme; setTheme: (theme: Theme) => void } {
  const theme = useSyncExternalStore(subscribe, current, () => 'dark' as const)

  const setTheme = (next: Theme) => {
    try {
      localStorage.setItem(STORAGE, next)
    } catch {
      // Without storage the choice lasts until the page is reloaded.
    }
    apply(next)
  }

  return { theme, setTheme }
}
