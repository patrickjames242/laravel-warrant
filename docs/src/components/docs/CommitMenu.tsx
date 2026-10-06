import {
  FloatingFocusManager,
  FloatingPortal,
  autoUpdate,
  flip,
  offset,
  shift,
  size,
  useClick,
  useDismiss,
  useFloating,
  useInteractions,
  useRole,
  useTransitionStyles,
} from '@floating-ui/react'
import { useCallback, useEffect, useEffectEvent, useId, useState } from 'react'
import type { ChangeKind, ChangesResponse, CommitRequest, CommitResponse, FileChange } from '../../docs/editorProtocol'
import { EDITOR_CHANGES_ENDPOINT, EDITOR_COMMIT_ENDPOINT } from '../../docs/editorProtocol'
import { call, errorText } from './editorApi'

type Scope = 'page' | 'docs' | 'all'

/** Which changes each scope offers, given the open page's file. */
const SCOPES: { key: Scope; label: string; offers: (change: FileChange, page: string) => boolean }[] = [
  { key: 'page', label: 'This page', offers: (change, page) => change.path === page },
  { key: 'docs', label: 'Docs pages', offers: (change) => change.docs },
  { key: 'all', label: 'Everything', offers: () => true },
]

/** The letter beside a file, as git and most editors mark the same kinds of change. */
const KIND: Record<ChangeKind, { mark: string; label: string; className: string }> = {
  modified: { mark: 'M', label: 'Modified', className: 'text-change-modified' },
  added: { mark: 'A', label: 'Added', className: 'text-change-added' },
  untracked: { mark: 'U', label: 'Untracked: not in git yet', className: 'text-change-added' },
  deleted: { mark: 'D', label: 'Deleted', className: 'text-change-deleted' },
  renamed: { mark: 'R', label: 'Renamed', className: 'text-change-modified' },
  conflicted: { mark: '!', label: 'Unresolved merge conflict', className: 'text-coral' },
}

type List = { status: 'loading' } | { status: 'failed'; message: string } | { status: 'ready'; changes: ChangesResponse }

interface CommitMenuProps {
  slug: string
  /** Saves anything the editor has not yet written to disk, and reports whether it all is. */
  prepare: () => Promise<boolean>
  onCommitted: (result: CommitResponse) => void
}

function offered(changes: ChangesResponse, scope: Scope): FileChange[] {
  const rule = SCOPES.find((entry) => entry.key === scope) ?? SCOPES[0]
  return changes.changes.filter((change) => change.kind !== 'conflicted' && rule?.offers(change, changes.page))
}

/** The message a commit of `scope` starts with, until the reader writes their own. */
function suggestedMessage(changes: ChangesResponse | undefined, scope: Scope): string {
  if (scope === 'page' && changes) return `docs: update ${changes.page.split('/').pop()?.replace(/\.md$/, '') ?? 'the page'}`
  if (scope === 'docs') return 'docs: update the docs pages'
  return ''
}

/**
 * The page editor's commit button and the menu it opens. The menu lists the
 * repository's uncommitted changes, untracked files included, narrowed to this
 * page's file, to the docs' Markdown pages, or to everything, with a checkbox
 * for each so any can be left out. It commits the files left checked, and only
 * those, with or without pushing the branch afterwards.
 */
export function CommitMenu({ slug, prepare, onCommitted }: CommitMenuProps) {
  const [open, setOpen] = useState(false)
  const [list, setList] = useState<List>({ status: 'loading' })
  const [scope, setScope] = useState<Scope>('page')
  const [selected, setSelected] = useState<ReadonlySet<string>>(new Set())
  /** What the reader has typed as the message; `null` until they type, while the suggestion stands in. */
  const [written, setWritten] = useState<string | null>(null)
  const [working, setWorking] = useState<'commit' | 'push'>()
  const [error, setError] = useState<string>()
  const messageId = useId()

  const changes = list.status === 'ready' ? list.changes : undefined
  const message = written ?? suggestedMessage(changes, scope)
  const files = changes ? offered(changes, scope) : []
  const chosen = files.filter((change) => selected.has(change.path))

  const choose = (next: ChangesResponse, nextScope: Scope) => {
    setScope(nextScope)
    setSelected(new Set(offered(next, nextScope).map((change) => change.path)))
  }

  /**
   * Reads the changes again. The list already shown stays until the new one
   * arrives, so a menu opening with it never swaps its content mid-animation;
   * a failed refresh keeps it too and says what went wrong.
   */
  const load = async (nextScope: Scope) => {
    const fail = (message: string) => {
      setList((shown) => (shown.status === 'ready' ? shown : { status: 'failed', message }))
      setError((shown) => shown ?? `Could not read the changes: ${message}`)
    }
    try {
      const { status, body } = await call(slug, undefined, EDITOR_CHANGES_ENDPOINT)
      if (status !== 200) {
        fail(errorText(body))
        return
      }
      const next = body as ChangesResponse
      setList({ status: 'ready', changes: next })
      choose(next, nextScope)
    } catch (reason) {
      fail(String(reason))
    }
  }

  const adopt = useEffectEvent((next: ChangesResponse) => {
    setList({ status: 'ready', changes: next })
    choose(next, scope)
  })

  // Read once as the editor opens, so the menu's first opening already has the list.
  useEffect(() => {
    let cancelled = false
    void call(slug, undefined, EDITOR_CHANGES_ENDPOINT)
      .then(({ status, body }) => {
        if (!cancelled && status === 200) adopt(body as ChangesResponse)
      })
      .catch(() => undefined)
    return () => {
      cancelled = true
    }
  }, [slug])

  const changeOpen = (next: boolean) => {
    setOpen(next)
    if (!next) return
    setError(undefined)
    void load(scope)
  }

  const { refs, floatingStyles, context } = useFloating({
    open,
    onOpenChange: changeOpen,
    placement: 'bottom-end',
    whileElementsMounted: autoUpdate,
    middleware: [
      offset(8),
      flip({ padding: 12 }),
      shift({ padding: 12 }),
      size({
        padding: 12,
        apply({ availableHeight, elements }) {
          elements.floating.style.maxHeight = `${Math.max(260, availableHeight)}px`
        },
      }),
    ],
  })
  const setReference = useCallback(
    (node: HTMLElement | null) => {
      refs.setReference(node)
    },
    [refs],
  )
  const setFloating = useCallback(
    (node: HTMLElement | null) => {
      refs.setFloating(node)
    },
    [refs],
  )
  // It grows out of the button's corner and fades, opening a little slower than it closes.
  const { isMounted, styles: transitionStyles } = useTransitionStyles(context, {
    duration: { open: 200, close: 140 },
    initial: { opacity: 0, transform: 'translateY(-6px) scale(0.97)' },
    common: { transformOrigin: 'top right', transitionTimingFunction: 'var(--ease-glide)' },
  })
  const { getReferenceProps, getFloatingProps } = useInteractions([
    useClick(context),
    useDismiss(context),
    useRole(context, { role: 'dialog' }),
  ])

  const toggle = (path: string) => {
    const next = new Set(selected)
    if (next.has(path)) next.delete(path)
    else next.add(path)
    setSelected(next)
  }

  const everyChosen = files.length > 0 && chosen.length === files.length

  const submit = async (push: boolean) => {
    if (working || chosen.length === 0 || message.trim() === '') return
    setWorking(push ? 'push' : 'commit')
    setError(undefined)
    try {
      if (!(await prepare())) {
        setError('The page has changes that could not be saved, so nothing was committed.')
        return
      }
      const { status, body } = await call(
        slug,
        {
          method: 'POST',
          body: JSON.stringify({ message, paths: chosen.map((change) => change.path), push } satisfies CommitRequest),
        },
        EDITOR_COMMIT_ENDPOINT,
      )
      if (status !== 200) {
        setError(`Could not commit: ${errorText(body)}`)
        return
      }
      const result = body as CommitResponse
      onCommitted(result)
      setWritten(null)
      if (result.pushError) {
        setError(`Committed ${result.commit}, but the push failed: ${result.pushError}`)
        void load(scope)
      } else {
        setOpen(false)
      }
    } catch (reason) {
      setError(`Could not commit: ${String(reason)}`)
    } finally {
      setWorking(undefined)
    }
  }

  return (
    <>
      <button
        ref={setReference}
        type="button"
        title="Commit changes to git"
        className={`flex h-8 cursor-pointer items-center gap-1.5 rounded-md px-3 text-[14px] font-semibold text-ink hover:bg-coral-hover ${
          open ? 'bg-coral-hover' : 'bg-coral-strong'
        }`}
        {...getReferenceProps()}
      >
        Commit
        <svg
          aria-hidden="true"
          viewBox="0 0 12 12"
          className={`size-3 transition-transform duration-200 ease-glide ${open ? 'rotate-180' : ''}`}
        >
          <path d="M2.5 4.5 6 8l3.5-3.5" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
      </button>

      {isMounted && (
        <FloatingPortal>
          <FloatingFocusManager context={context} modal={false}>
            <div
              ref={setFloating}
              style={floatingStyles}
              className="z-[60] flex w-[min(460px,calc(100vw-24px))]"
              {...getFloatingProps({
                // Esc closes this menu, not the editor around it.
                onKeyDown: (event) => {
                  if (event.key !== 'Escape') return
                  event.preventDefault()
                  event.stopPropagation()
                  setOpen(false)
                },
              })}
            >
              <div
                style={transitionStyles}
                className="flex max-h-[inherit] w-full flex-col overflow-hidden rounded-[17px] border border-line-3 bg-ink text-sand shadow-[0_10px_28px_-14px_color-mix(in_srgb,var(--color-shade)_55%,transparent)] light:border-pane-edge"
              >
                <div className="flex flex-none items-center justify-between gap-3 border-b border-line-2 px-4 py-3 light:border-pane-edge">
                  <span className="font-mono text-[12px] leading-none font-bold tracking-[.12em] text-sand">COMMIT</span>
                  {changes && <span className="truncate font-mono text-[12.5px] leading-none text-umber">on {changes.branch}</span>}
                </div>

                <div className="flex flex-none gap-1 border-b border-line-2 p-2 light:border-pane-edge" role="radiogroup" aria-label="Which changes">
                  {SCOPES.map((entry) => {
                    const on = entry.key === scope
                    const count = changes ? offered(changes, entry.key).length : undefined
                    return (
                      <button
                        key={entry.key}
                        type="button"
                        role="radio"
                        aria-checked={on}
                        onClick={() => {
                          if (changes) choose(changes, entry.key)
                          else setScope(entry.key)
                        }}
                        className={`flex h-8 flex-1 cursor-pointer items-center justify-center gap-1.5 rounded-md border text-[13.5px] font-medium ${
                          on ? 'border-coral bg-coral/10 text-coral' : 'border-transparent text-tan hover:border-line-3'
                        }`}
                      >
                        {entry.label}
                        {count !== undefined && <span className="font-mono text-[11.5px] opacity-70">{count}</span>}
                      </button>
                    )
                  })}
                </div>

                <div className="min-h-0 flex-1 overflow-y-auto">
                  {list.status === 'loading' && <p className="px-4 py-5 text-[14px] text-taupe">Reading git status…</p>}
                  {list.status === 'failed' && (
                    <p role="alert" className="px-4 py-5 text-[14px] text-coral-soft">
                      Could not read the changes: {list.message}
                    </p>
                  )}
                  {list.status === 'ready' && files.length === 0 && (
                    <p className="px-4 py-5 text-[14px] text-taupe">
                      {scope === 'page' ? 'This page has no uncommitted changes.' : 'Nothing to commit here.'}
                    </p>
                  )}
                  {files.length > 0 && (
                    <ul className="py-1.5">
                      <li>
                        <label className="flex cursor-pointer items-center gap-2.5 px-4 py-1.5 text-[13px] text-umber">
                          <input
                            type="checkbox"
                            checked={everyChosen}
                            ref={(box) => {
                              if (box) box.indeterminate = chosen.length > 0 && !everyChosen
                            }}
                            onChange={() => {
                              setSelected(everyChosen ? new Set() : new Set(files.map((change) => change.path)))
                            }}
                            className="size-3.5 flex-none accent-coral"
                          />
                          {chosen.length} of {files.length} {files.length === 1 ? 'file' : 'files'}
                        </label>
                      </li>
                      {files.map((change) => {
                        const kind = KIND[change.kind]
                        return (
                          <li key={change.path}>
                            <label
                              title={change.from ? `${change.from} → ${change.path}` : change.path}
                              className="flex cursor-pointer items-center gap-2.5 px-4 py-1.5 hover:bg-cream/4"
                            >
                              <input
                                type="checkbox"
                                checked={selected.has(change.path)}
                                onChange={() => {
                                  toggle(change.path)
                                }}
                                className="size-3.5 flex-none accent-coral"
                              />
                              <span
                                title={kind.label}
                                className={`w-3 flex-none text-center font-mono text-[12px] font-bold ${kind.className}`}
                              >
                                {kind.mark}
                              </span>
                              <span className="min-w-0 truncate font-mono text-[12.5px] text-sand" dir="rtl">
                                {/* Right-to-left so a long path keeps its file name and loses its folders. */}
                                <bdi>{change.path}</bdi>
                              </span>
                            </label>
                          </li>
                        )
                      })}
                    </ul>
                  )}
                </div>

                <div className="flex flex-none flex-col gap-2.5 border-t border-line-2 p-3 light:border-pane-edge">
                  <label htmlFor={messageId} className="sr-only">
                    Commit message
                  </label>
                  <textarea
                    id={messageId}
                    value={message}
                    onChange={(event) => {
                      setWritten(event.target.value)
                    }}
                    onKeyDown={(event) => {
                      if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
                        event.preventDefault()
                        void submit(false)
                      }
                    }}
                    rows={2}
                    placeholder="Commit message"
                    className="w-full resize-y rounded-md border border-line-3 bg-pane px-3 py-2 font-mono text-[13px] leading-[1.5] text-cream placeholder:text-umber focus:border-coral focus:outline-none light:border-pane-edge"
                  />
                  {error && (
                    <p role="alert" className="text-[13.5px] leading-normal text-coral-soft">
                      {error}
                    </p>
                  )}
                  <div className="flex items-center justify-end gap-2">
                    <button
                      type="button"
                      disabled={working !== undefined || chosen.length === 0 || message.trim() === ''}
                      onClick={() => {
                        void submit(true)
                      }}
                      className="h-8 cursor-pointer rounded-md border border-line-3 px-3 text-[14px] font-medium text-tan enabled:hover:border-taupe enabled:hover:text-sand disabled:cursor-default disabled:opacity-40"
                    >
                      {working === 'push' ? 'Pushing…' : 'Commit & push'}
                    </button>
                    <button
                      type="button"
                      disabled={working !== undefined || chosen.length === 0 || message.trim() === ''}
                      onClick={() => {
                        void submit(false)
                      }}
                      title="Commit (⌘↩)"
                      className="h-8 cursor-pointer rounded-md bg-coral-strong px-4 text-[14px] font-semibold text-ink enabled:hover:bg-coral-hover disabled:cursor-default disabled:opacity-40"
                    >
                      {working === 'commit' ? 'Committing…' : 'Commit'}
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </FloatingFocusManager>
        </FloatingPortal>
      )}
    </>
  )
}
