import { lazy, useEffect, useState } from 'react'

/**
 * The in-browser Markdown editor, loaded on first use. A build replaces
 * `import.meta.env.DEV` with false and drops it.
 *
 * It is created here, in a module no docs data flows through, so it keeps one
 * identity for the whole session. A page's hot update re-runs the modules that
 * read the page list, and a `lazy()` made in one of them would come back as a
 * new component, remounting the editor and losing an unsaved draft.
 */
export const PageEditor = import.meta.env.DEV ? lazy(() => import('./PageEditor')) : undefined

const KEY = 'docs-editor-open'

function readOpen(): boolean {
  try {
    return sessionStorage.getItem(KEY) === '1'
  } catch {
    return false
  }
}

/**
 * Whether the page editor is open. It stays open across page changes and
 * reloads for the rest of the browser session, so moving between pages keeps
 * the editor where it was. Outside the dev server it is always closed.
 */
export function useEditorOpen(): [boolean, (open: boolean) => void] {
  const [open, setOpen] = useState(() => import.meta.env.DEV && readOpen())

  useEffect(() => {
    if (!import.meta.env.DEV) return
    try {
      if (open) sessionStorage.setItem(KEY, '1')
      else sessionStorage.removeItem(KEY)
    } catch {
      // Storage can be unavailable; the editor then forgets it was open on reload.
    }
  }, [open])

  return [open, setOpen]
}
