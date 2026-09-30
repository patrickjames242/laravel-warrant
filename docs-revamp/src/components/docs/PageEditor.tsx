import type { EditorView } from '@codemirror/view'
import { useBlocker } from '@tanstack/react-router'
import type { KeyboardEvent } from 'react'
import { useEffect, useEffectEvent, useState } from 'react'
import type { ChangedEvent, PageSource, SaveConflict, SaveRequest, SaveResponse } from '../../docs/editorProtocol'
import {
  EDITOR_CHANGED_EVENT,
  EDITOR_ENDPOINT,
  EDITOR_HEADER,
  EDITOR_ROLLBACK_ENDPOINT,
} from '../../docs/editorProtocol'
import { MarkdownEditor } from './MarkdownEditor'
import { PaneDivider } from './PaneDivider'
import { useScrollSync, useSyncPreference } from './scrollSync'

interface PageEditorProps {
  slug: string
  onClose: () => void
}

type Load = { status: 'loading' } | { status: 'failed'; message: string } | { status: 'ready'; path: string }

/**
 * Why the editor is showing the file's current text beside the draft: a save
 * was refused because the file had moved on, or the file changed on disk while
 * the draft had unsaved edits.
 */
interface Divergence {
  reason: 'conflict' | 'disk'
  theirs: PageSource
}

const DISCARD = 'Your latest changes to this page could not be saved. Discard them?'

/** How long typing has to pause before the draft is saved. */
const AUTOSAVE_DELAY = 800

async function call(
  slug: string,
  init?: RequestInit,
  endpoint = EDITOR_ENDPOINT,
): Promise<{ status: number; body: unknown }> {
  const response = await fetch(`${endpoint}?slug=${encodeURIComponent(slug)}`, {
    ...init,
    headers: { [EDITOR_HEADER]: '1', 'Content-Type': 'application/json' },
  })
  return { status: response.status, body: (await response.json()) as unknown }
}

function errorText(body: unknown): string {
  return typeof body === 'object' && body !== null && 'error' in body && typeof body.error === 'string'
    ? body.error
    : 'The dev server did not say why.'
}

/**
 * Edits one docs page's Markdown in the browser. The draft saves itself once
 * typing pauses, writing the file through the dev server, and the page beside
 * the editor re-renders from it as any edit to the file would. While syncing is on, scrolling either the editor or
 * the article brings the other to the same place.
 *
 * It is tied to git: the lines that differ from the last commit are marked as
 * they are typed, and a rollback returns the file to that commit. Loaded only
 * under `vite dev`.
 */
export default function PageEditor({ slug, onClose }: PageEditorProps) {
  const [load, setLoad] = useState<Load>({ status: 'loading' })
  const [draft, setDraft] = useState('')
  const [saved, setSaved] = useState('')
  const [version, setVersion] = useState('')
  const [committed, setCommittedSource] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const [rollingBack, setRollingBack] = useState(false)
  /** A draft that failed to save. It is not tried again until the text changes. */
  const [failed, setFailed] = useState<string>()
  const [error, setError] = useState<string>()
  const [divergence, setDivergence] = useState<Divergence>()
  const [view, setView] = useState<EditorView | null>(null)
  const [sync, setSync] = useSyncPreference()

  useScrollSync(view, sync)

  // Marks the page as being edited, which hides the window's scrollbar; the stylesheet has the rule.
  useEffect(() => {
    const root = document.documentElement
    root.toggleAttribute('data-page-editor-open', true)
    return () => {
      root.toggleAttribute('data-page-editor-open', false)
    }
  }, [])

  const dirty = draft !== saved
  const busy = saving || rollingBack

  const adopt = (page: PageSource) => {
    setDraft(page.source)
    setSaved(page.source)
    setVersion(page.version)
    setCommittedSource(page.base)
    setDivergence(undefined)
    setError(undefined)
  }

  useEffect(() => {
    let cancelled = false
    void call(slug)
      .then(({ status, body }) => {
        if (cancelled) return
        if (status !== 200) {
          setLoad({ status: 'failed', message: errorText(body) })
          return
        }
        const page = body as PageSource
        adopt(page)
        setLoad({ status: 'ready', path: page.path })
      })
      .catch((reason: unknown) => {
        if (!cancelled) setLoad({ status: 'failed', message: String(reason) })
      })
    return () => {
      cancelled = true
    }
  }, [slug])

  // The file changed on disk. A clean draft takes the new text; a dirty one is
  // kept, with the new text offered beside it. A change that matches the draft
  // is this editor's own save arriving.
  const onDiskChange = useEffectEvent(async (changed: ChangedEvent) => {
    if (changed.slug !== slug || load.status !== 'ready') return
    const { status, body: response } = await call(slug)
    const body = response as PageSource
    if (status !== 200 || body.version === version) return
    if (body.source === draft) {
      setSaved(body.source)
      setVersion(body.version)
      setCommittedSource(body.base)
      return
    }
    if (dirty) setDivergence({ reason: 'disk', theirs: body })
    else adopt(body)
  })

  useEffect(() => {
    const hot = import.meta.hot
    if (!hot) return
    const listener = (changed: ChangedEvent) => {
      void onDiskChange(changed)
    }
    hot.on(EDITOR_CHANGED_EVENT, listener)
    return () => {
      hot.off(EDITOR_CHANGED_EVENT, listener)
    }
  }, [])

  // A commit made elsewhere, such as in a terminal, changes what the marks
  // compare against without touching the file, so it is looked up again
  // whenever the reader comes back to the page.
  const refreshCommitted = useEffectEvent(async () => {
    if (load.status !== 'ready') return
    const { status, body } = await call(slug)
    if (status === 200) setCommittedSource((body as PageSource).base)
  })

  useEffect(() => {
    const onReturn = () => {
      if (document.visibilityState === 'visible') void refreshCommitted()
    }
    window.addEventListener('focus', onReturn)
    document.addEventListener('visibilitychange', onReturn)
    return () => {
      window.removeEventListener('focus', onReturn)
      document.removeEventListener('visibilitychange', onReturn)
    }
  }, [])

  /** Writes the draft to the file, and reports whether it is now saved. */
  const save = async (from = version): Promise<boolean> => {
    if (busy || load.status !== 'ready') return false
    setSaving(true)
    setError(undefined)
    const source = draft
    try {
      const { status, body: response } = await call(slug, {
        method: 'PUT',
        body: JSON.stringify({ source, version: from } satisfies SaveRequest),
      })
      const body = response as SaveResponse | SaveConflict
      if (status === 200 && !('conflict' in body)) {
        setSaved(source)
        setVersion(body.version)
        setDivergence(undefined)
        setFailed(undefined)
        return true
      }
      if (status === 409 && 'conflict' in body) setDivergence({ reason: 'conflict', theirs: body })
      else {
        setError(`Could not save: ${errorText(body)}`)
        setFailed(source)
      }
    } catch (reason) {
      setError(`Could not save: ${String(reason)}`)
      setFailed(source)
    } finally {
      setSaving(false)
    }
    return false
  }

  // Save once typing pauses. It holds off while a save is under way, while the
  // file has moved on and the reader has yet to choose which text to keep, and
  // for a draft that already failed, until it is edited again.
  const autosave = useEffectEvent(() => {
    void save()
  })
  const waiting = !dirty || busy || divergence !== undefined || load.status !== 'ready' || draft === failed
  useEffect(() => {
    if (waiting) return
    const timer = window.setTimeout(autosave, AUTOSAVE_DELAY)
    return () => {
      window.clearTimeout(timer)
    }
  }, [draft, waiting])

  /** Saves what is still unsaved before the editor lets go of it, and asks before losing it if that fails. */
  const mayLeave = async (): Promise<boolean> => {
    if (!dirty || (await save())) return true
    return window.confirm(DISCARD)
  }

  useBlocker({
    shouldBlockFn: async () => !(await mayLeave()),
    enableBeforeUnload: dirty,
  })

  /** The file differs from the last commit, on disk or in the draft, so a rollback would change it. */
  const canRollBack = committed !== null && (draft !== committed || saved !== committed)

  const rollBack = async () => {
    if (busy || load.status !== 'ready' || !canRollBack) return
    const confirmed = window.confirm(
      `Roll ${load.path} back to the last commit?\n\nEvery uncommitted change to it will be lost, saved or not, staged or not.`,
    )
    if (!confirmed) return
    setRollingBack(true)
    setError(undefined)
    try {
      const { status, body } = await call(slug, { method: 'POST' }, EDITOR_ROLLBACK_ENDPOINT)
      if (status === 200) adopt(body as PageSource)
      else setError(`Could not roll back: ${errorText(body)}`)
    } catch (reason) {
      setError(`Could not roll back: ${String(reason)}`)
    } finally {
      setRollingBack(false)
    }
  }

  const close = async () => {
    if (await mayLeave()) onClose()
  }

  const onKeyDown = (event: KeyboardEvent) => {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') {
      event.preventDefault()
      void save()
    } else if (event.key === 'Escape') {
      event.preventDefault()
      void close()
    }
  }

  const progress =
    load.status === 'loading'
      ? 'Loading…'
      : saving
        ? 'Saving…'
        : rollingBack
          ? 'Rolling back…'
          : dirty
            ? draft === failed
              ? 'Not saved'
              : 'Unsaved changes'
            : 'Saved'
  const gitState =
    load.status !== 'ready' ? undefined : committed === null ? 'not in git yet' : saved !== committed ? 'uncommitted' : undefined
  const status = gitState ? `${progress} · ${gitState}` : progress

  return (
    <section
      aria-label="Page editor"
      onKeyDown={onKeyDown}
      className="flex min-h-0 flex-1 flex-col bg-surface-2 text-sand"
    >
      <PaneDivider />
      <div className="flex flex-none items-center gap-3 border-b border-line-2 bg-surface px-4 py-2.5">
        <div className="min-w-0 flex-1">
          <div className="truncate font-mono text-[13px] leading-[1.3] text-cream">
            {load.status === 'ready' ? load.path : slug || 'index'}
          </div>
          <div
            aria-live="polite"
            className={`mt-0.5 font-mono text-[11.5px] leading-[1.3] tracking-[.06em] ${dirty ? 'text-coral' : 'text-umber'}`}
          >
            {status}
          </div>
        </div>
        <button
          type="button"
          aria-pressed={sync}
          onClick={() => {
            setSync(!sync)
          }}
          title="Keep the editor and the article scrolled to the same place"
          className={`flex h-8 cursor-pointer items-center gap-1.5 rounded-md border px-2.5 font-mono text-[12.5px] font-medium ${
            sync ? 'border-coral bg-coral/10 text-coral' : 'border-line-3 text-taupe hover:border-taupe'
          }`}
        >
          <span aria-hidden="true">⇅</span>
          Sync
        </button>
        <button
          type="button"
          disabled={!canRollBack || busy}
          onClick={() => {
            void rollBack()
          }}
          title={
            committed === null
              ? 'This page has never been committed, so there is nothing to roll back to'
              : 'Discard every uncommitted change to this file and return it to the last commit'
          }
          className="h-8 cursor-pointer rounded-md border border-line-3 px-3 text-[14px] font-medium text-tan enabled:hover:border-coral enabled:hover:text-coral-soft disabled:cursor-default disabled:opacity-40"
        >
          Rollback
        </button>
        <button
          type="button"
          onClick={() => {
            void close()
          }}
          aria-label="Close the editor"
          title="Close (Esc)"
          className="grid size-8 cursor-pointer place-items-center rounded-md border border-line-3 text-[17px] leading-none text-tan hover:border-taupe"
        >
          ×
        </button>
      </div>

      {divergence && (
        <div role="alert" className="flex flex-none flex-wrap items-center gap-x-3 gap-y-2 border-b border-coral-deep bg-coral/8 px-4 py-3 text-[14.5px] leading-normal">
          <span className="min-w-0 flex-[1_1_260px] text-cream">
            {divergence.reason === 'conflict'
              ? 'Not saved: the file changed on disk after you started editing.'
              : 'The file changed on disk while you have unsaved changes.'}
          </span>
          <button
            type="button"
            onClick={() => {
              adopt(divergence.theirs)
            }}
            className="h-8 cursor-pointer rounded-md border border-line-5 px-3 text-[14px] font-medium text-cream hover:border-taupe"
          >
            Use the file on disk
          </button>
          <button
            type="button"
            onClick={() => {
              setDivergence(undefined)
              void save(divergence.theirs.version)
            }}
            className="h-8 cursor-pointer rounded-md border border-coral px-3 text-[14px] font-medium text-coral hover:bg-coral/10"
          >
            Overwrite it with mine
          </button>
        </div>
      )}

      {error && (
        <div role="alert" className="flex-none border-b border-coral-deep bg-coral/8 px-4 py-3 text-[14.5px] leading-normal text-cream">
          {error}
        </div>
      )}

      {load.status === 'failed' && (
        <div role="alert" className="p-5 text-[15px] leading-normal text-tan">
          Could not open this page: {load.message}
        </div>
      )}
      {load.status === 'ready' && (
        <MarkdownEditor
          value={draft}
          committed={committed}
          onChange={setDraft}
          onView={(next) => {
            setView(next)
            next?.contentDOM.focus({ preventScroll: true })
          }}
          label="Markdown source"
        />
      )}
    </section>
  )
}
