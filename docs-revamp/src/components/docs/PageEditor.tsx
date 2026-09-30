import type { EditorView } from '@codemirror/view'
import { useBlocker } from '@tanstack/react-router'
import type { KeyboardEvent } from 'react'
import { useEffect, useEffectEvent, useState } from 'react'
import type { ChangedEvent, PageSource, SaveConflict, SaveRequest, SaveResponse } from '../../docs/editorProtocol'
import { EDITOR_CHANGED_EVENT, EDITOR_ENDPOINT, EDITOR_HEADER } from '../../docs/editorProtocol'
import { MarkdownEditor } from './MarkdownEditor'
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

const DISCARD = 'Discard your unsaved changes to this page?'

async function call(slug: string, init?: RequestInit): Promise<{ status: number; body: unknown }> {
  const response = await fetch(`${EDITOR_ENDPOINT}?slug=${encodeURIComponent(slug)}`, {
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
 * Edits one docs page's Markdown in the browser. Saving writes the file through
 * the dev server, and the page beside the editor re-renders from it as any
 * edit to the file would. While syncing is on, scrolling either the editor or
 * the article brings the other to the same place. Loaded only under `vite dev`.
 */
export default function PageEditor({ slug, onClose }: PageEditorProps) {
  const [load, setLoad] = useState<Load>({ status: 'loading' })
  const [draft, setDraft] = useState('')
  const [saved, setSaved] = useState('')
  const [version, setVersion] = useState('')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string>()
  const [divergence, setDivergence] = useState<Divergence>()
  const [view, setView] = useState<EditorView | null>(null)
  const [sync, setSync] = useSyncPreference()

  useScrollSync(view, sync)

  const dirty = draft !== saved

  const adopt = (page: PageSource) => {
    setDraft(page.source)
    setSaved(page.source)
    setVersion(page.version)
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

  useBlocker({
    shouldBlockFn: () => dirty && !window.confirm(DISCARD),
    enableBeforeUnload: dirty,
  })

  const save = async (from = version) => {
    if (saving || load.status !== 'ready') return
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
      } else if (status === 409 && 'conflict' in body) {
        setDivergence({ reason: 'conflict', theirs: body })
      } else {
        setError(errorText(body))
      }
    } catch (reason) {
      setError(String(reason))
    } finally {
      setSaving(false)
    }
  }

  const close = () => {
    if (dirty && !window.confirm(DISCARD)) return
    onClose()
  }

  const onKeyDown = (event: KeyboardEvent) => {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') {
      event.preventDefault()
      void save()
    } else if (event.key === 'Escape') {
      event.preventDefault()
      close()
    }
  }

  const status =
    load.status === 'loading'
      ? 'Loading…'
      : saving
        ? 'Saving…'
        : dirty
          ? 'Unsaved changes'
          : 'Saved'

  return (
    <section
      aria-label="Page editor"
      onKeyDown={onKeyDown}
      className="flex min-h-0 flex-1 flex-col bg-surface-2 text-sand"
    >
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
          disabled={!dirty || saving}
          onClick={() => {
            setDraft(saved)
          }}
          className="h-8 cursor-pointer rounded-md border border-line-3 px-3 text-[14px] font-medium text-tan enabled:hover:border-taupe disabled:cursor-default disabled:opacity-40"
        >
          Revert
        </button>
        <button
          type="button"
          disabled={!dirty || saving}
          onClick={() => {
            void save()
          }}
          title="Save (⌘S)"
          className="h-8 cursor-pointer rounded-md bg-coral-strong px-3.5 text-[14px] font-bold text-ink enabled:hover:bg-coral-hover disabled:cursor-default disabled:opacity-40"
        >
          Save
        </button>
        <button
          type="button"
          onClick={close}
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
          Could not save: {error}
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
