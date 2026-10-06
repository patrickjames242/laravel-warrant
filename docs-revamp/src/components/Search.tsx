import {
  FloatingFocusManager,
  FloatingOverlay,
  FloatingPortal,
  useClick,
  useDismiss,
  useFloating,
  useInteractions,
  useRole,
  useTransitionStyles,
} from '@floating-ui/react'
import { useNavigate } from '@tanstack/react-router'
import type { KeyboardEvent, ReactNode, RefObject } from 'react'
import { useCallback, useEffect, useId, useMemo, useRef, useState } from 'react'
import type { Fragment, SearchHit } from '../docs/search'
import { DocLink } from './docs/DocLink'

type SearchModule = typeof import('../docs/search')

/**
 * The search index, built the first time search is opened and kept for the
 * rest of the visit. Until then neither the index nor the search library is
 * downloaded.
 */
let loading: Promise<SearchModule> | undefined
function loadSearch(): Promise<SearchModule> {
  loading ??= import('../docs/search').catch((error: unknown) => {
    loading = undefined
    throw error
  })
  return loading
}

const MAC = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.userAgent)

/** Whether a key pressed in `target` is being typed into it. */
function isTyping(target: EventTarget | null): boolean {
  return (
    target instanceof HTMLElement &&
    (target.isContentEditable || target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target instanceof HTMLSelectElement)
  )
}

/**
 * The header's search button and the dialog it opens. ⌘K, or Ctrl+K, opens
 * and closes it from anywhere, and `/` opens it when nothing is being typed in.
 */
export function Search() {
  const [open, setOpen] = useState(false)
  const input = useRef<HTMLInputElement>(null)

  const { refs, context } = useFloating({ open, onOpenChange: setOpen })
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
  const { isMounted, styles: transitionStyles } = useTransitionStyles(context, {
    duration: { open: 200, close: 140 },
    initial: { opacity: 0, transform: 'translateY(-8px) scale(0.985)' },
    common: { transformOrigin: 'top center', transitionTimingFunction: 'var(--ease-glide)' },
  })
  const { styles: overlayStyles } = useTransitionStyles(context, {
    duration: { open: 200, close: 140 },
  })
  const { getReferenceProps, getFloatingProps } = useInteractions([
    useClick(context),
    useDismiss(context, { outsidePressEvent: 'mousedown' }),
    useRole(context, { role: 'dialog' }),
  ])

  useEffect(() => {
    const onKey = (event: globalThis.KeyboardEvent) => {
      if (event.key.toLowerCase() === 'k' && (event.metaKey || event.ctrlKey) && !event.altKey && !event.shiftKey) {
        event.preventDefault()
        setOpen((was) => !was)
      } else if (event.key === '/' && !event.metaKey && !event.ctrlKey && !event.altKey && !isTyping(event.target)) {
        event.preventDefault()
        setOpen(true)
      }
    }
    window.addEventListener('keydown', onKey)
    return () => {
      window.removeEventListener('keydown', onKey)
    }
  }, [])

  const close = useCallback(() => {
    setOpen(false)
  }, [])

  return (
    <>
      <button
        ref={setReference}
        type="button"
        aria-label="Search the docs"
        aria-keyshortcuts={MAC ? 'Meta+K' : 'Control+K'}
        // Starts fetching the index as soon as the reader heads for the button. Below
        // 1280px it is only the icon, so the header's links and buttons keep their room.
        onPointerEnter={() => {
          void loadSearch().catch(() => undefined)
        }}
        className={`flex h-9 min-w-9 flex-none cursor-pointer items-center justify-center gap-2 rounded-md border bg-surface light:bg-surface/45 text-[14.5px] leading-none font-medium hover:border-line-5 hover:text-cream xl:w-44 xl:justify-start xl:pr-1.5 xl:pl-2.5 ${
          open ? 'border-line-5 text-cream' : 'border-line-3 text-taupe'
        }`}
        {...getReferenceProps()}
      >
        <SearchIcon />
        <span className="hidden flex-1 text-left xl:inline">Search</span>
        <kbd className="hidden rounded-[4.5px] border border-line-3 px-1.5 py-1 font-mono text-[11.5px] leading-none font-medium text-umber xl:inline">
          {MAC ? '⌘K' : 'Ctrl K'}
        </kbd>
      </button>

      {isMounted && (
        <FloatingPortal>
          <FloatingOverlay
            lockScroll
            style={overlayStyles}
            className="z-[70] flex flex-col items-center bg-shade/60 px-4 pt-[min(12vh,112px)] backdrop-blur-[2px]"
          >
            <FloatingFocusManager context={context} initialFocus={input}>
              <div
                ref={setFloating}
                aria-label="Search the docs"
                className="w-full max-w-[680px]"
                {...getFloatingProps()}
              >
                <div style={transitionStyles}>
                  <SearchPanel input={input} onClose={close} />
                </div>
              </div>
            </FloatingFocusManager>
          </FloatingOverlay>
        </FloatingPortal>
      )}
    </>
  )
}

type IndexState = { state: 'loading' } | { state: 'ready'; search: SearchModule['search'] } | { state: 'failed' }

interface SearchPanelProps {
  input: RefObject<HTMLInputElement | null>
  onClose: () => void
}

/** The query box and its results, which arrow keys move through and Enter opens. */
function SearchPanel({ input, onClose }: SearchPanelProps) {
  const [index, setIndex] = useState<IndexState>({ state: 'loading' })
  const [query, setQuery] = useState('')
  const [active, setActive] = useState(0)
  const options = useRef<(HTMLElement | null)[]>([])
  const navigate = useNavigate()
  const listId = useId()

  useEffect(() => {
    let live = true
    loadSearch().then(
      (module) => {
        if (live) setIndex({ state: 'ready', search: module.search })
      },
      () => {
        if (live) setIndex({ state: 'failed' })
      },
    )
    return () => {
      live = false
    }
  }, [])

  const trimmed = query.trim()
  const groups = useMemo(
    () => (index.state === 'ready' && trimmed ? index.search(trimmed) : []),
    [index, trimmed],
  )
  const hits = groups.flatMap((group) => group.hits)
  const current = Math.min(active, hits.length - 1)

  useEffect(() => {
    options.current[current]?.scrollIntoView({ block: 'nearest' })
  }, [current])

  const open = (hit: SearchHit) => {
    onClose()
    void navigate({ to: '/$/', params: { _splat: hit.slug }, hash: hit.id })
  }

  const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault()
      if (!hits.length) return
      const step = event.key === 'ArrowDown' ? 1 : -1
      setActive((current + step + hits.length) % hits.length)
    } else if (event.key === 'Enter' && !event.nativeEvent.isComposing) {
      const hit = hits[current]
      if (!hit) return
      event.preventDefault()
      open(hit)
    }
  }

  const optionId = (position: number) => `${listId}-${position}`
  // Where each page's hits start in the one list the arrow keys move through.
  const firsts = groups.map((_, at) => groups.slice(0, at).reduce((total, group) => total + group.hits.length, 0))

  return (
    <div className="flex max-h-[min(680px,calc(100dvh-min(12vh,112px)-32px))] flex-col overflow-hidden rounded-[14px] border border-line-3 bg-ink shadow-[0_24px_64px_-24px_color-mix(in_srgb,var(--color-shade)_80%,transparent)] light:border-pane-edge">
      <div className="flex h-15 flex-none items-center gap-3 border-b border-line-2 px-4.5 text-taupe">
        <SearchIcon />
        <input
          ref={input}
          type="search"
          value={query}
          onChange={(event) => {
            setQuery(event.target.value)
            setActive(0)
          }}
          onKeyDown={onKeyDown}
          placeholder="Search the docs"
          aria-label="Search the docs"
          role="combobox"
          aria-expanded={hits.length > 0}
          aria-controls={listId}
          aria-autocomplete="list"
          aria-activedescendant={hits.length ? optionId(current) : undefined}
          autoComplete="off"
          autoCorrect="off"
          spellCheck={false}
          className="h-full min-w-0 flex-1 bg-transparent text-[17px] text-cream outline-none placeholder:text-umber [&::-webkit-search-cancel-button]:hidden"
        />
        <button
          type="button"
          onClick={onClose}
          className="flex-none cursor-pointer rounded-[4.5px] border border-line-3 px-1.5 py-1 font-mono text-[11.5px] leading-none font-medium text-umber hover:border-line-5 hover:text-cream"
        >
          Esc
        </button>
      </div>

      <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain">
        {index.state === 'failed' ? (
          <Message>The search index could not be loaded. Check your connection and try again.</Message>
        ) : !trimmed ? (
          <Message>Search every page of the docs, prose and code alike.</Message>
        ) : index.state === 'loading' ? (
          <Message>Loading the search index…</Message>
        ) : !groups.length ? (
          <Message>
            No results for <span className="text-cream">“{trimmed}”</span>.
          </Message>
        ) : (
          <div id={listId} role="listbox" aria-label="Results" className="grid gap-1 p-2">
            {groups.map((group, groupAt) => (
              <div key={group.entry.page.slug} role="group" aria-label={group.entry.page.title} className="pb-1">
                <div className="flex items-baseline gap-2 px-3 pt-3 pb-2 font-mono text-[12.5px] leading-none font-semibold tracking-[.02em]">
                  <span className="min-w-0 truncate text-coral">{group.entry.page.title}</span>
                  {group.entry.groups.at(-1) && (
                    <span className="hidden min-w-0 flex-1 truncate text-umber sm:inline">
                      {group.entry.groups.at(-1)?.label}
                    </span>
                  )}
                </div>
                {group.hits.map((hit, hitAt) => {
                  const at = (firsts[groupAt] ?? 0) + hitAt
                  const on = at === current
                  return (
                    <div
                      key={hit.id ?? ''}
                      id={optionId(at)}
                      ref={(node) => {
                        options.current[at] = node
                      }}
                      role="option"
                      aria-selected={on}
                      onPointerMove={() => {
                        if (!on) setActive(at)
                      }}
                    >
                      <DocLink
                        slug={hit.slug}
                        hash={hit.id}
                        onClick={onClose}
                        // The marker is a straight bar that the rounded corners clip, not a border that follows them.
                        className={`relative grid gap-1 overflow-hidden rounded-[9px] px-3.5 py-2.5 before:absolute before:inset-y-0 before:left-0 before:w-0.5 ${
                          on ? 'bg-cream/6 before:bg-coral' : ''
                        }`}
                      >
                        <span className="text-[15.5px] leading-[1.3] font-medium text-cream">
                          {hit.heading ? <Highlighted fragments={hit.heading} /> : 'Overview'}
                        </span>
                        {hit.snippet.length > 0 && (
                          <span
                            className={`line-clamp-2 leading-[1.5] text-taupe ${
                              hit.code ? 'font-mono text-[13px]' : 'text-[14px]'
                            }`}
                          >
                            <Highlighted fragments={hit.snippet} />
                          </span>
                        )}
                      </DocLink>
                    </div>
                  )
                })}
              </div>
            ))}
          </div>
        )}
      </div>

      <div className="hidden flex-none items-center gap-4 border-t border-line-2 px-4.5 py-3 font-mono text-[12px] leading-none text-umber sm:flex">
        <span>
          <Key>↑</Key> <Key>↓</Key> to move
        </span>
        <span>
          <Key>↵</Key> to open
        </span>
        <span>
          <Key>esc</Key> to close
        </span>
      </div>
    </div>
  )
}

function Highlighted({ fragments }: { fragments: Fragment[] }) {
  return fragments.map((fragment, at) =>
    fragment.match ? (
      <mark key={at} className="bg-transparent font-semibold text-coral">
        {fragment.text}
      </mark>
    ) : (
      fragment.text
    ),
  )
}

function Message({ children }: { children: ReactNode }) {
  return <p className="px-5 py-10 text-center text-[15px] leading-normal text-taupe">{children}</p>
}

function Key({ children }: { children: ReactNode }) {
  return <kbd className="rounded-[4px] border border-line-3 px-1.5 py-0.5 text-sand">{children}</kbd>
}

function SearchIcon() {
  return (
    <svg aria-hidden width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" className="flex-none">
      <circle cx="11" cy="11" r="7" />
      <path d="M20 20l-3.6-3.6" />
    </svg>
  )
}
