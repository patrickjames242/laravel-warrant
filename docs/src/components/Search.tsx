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
import { useCallback, useEffect, useId, useLayoutEffect, useRef, useState } from 'react'
import type { Fragment, SearchGroup, SearchHit } from '../docs/search'
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

/**
 * A heavily damped spring from 0 to 1, sampled as a `linear()` easing: it
 * passes its target by about two percent and eases back to rest.
 */
const SPRING =
  'linear(0, 0.027, 0.096, 0.189, 0.294, 0.401, 0.505, 0.6, 0.685, 0.759, 0.822, 0.873, 0.915, 0.947, 0.972, 0.99, 1.003, 1.011, 1.016, 1.019, 1.02, 1.019, 1.018, 1.016, 1.014, 1.012, 1.01, 1.008, 1.006, 1.005, 1.004, 1.003, 1.002, 1.001, 1.001, 1, 1, 1, 1, 1, 1)'

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
  // The panel starts below its place and rises straight up, settling with the
  // faintest bounce. Closing is the same movement in reverse, gathering speed as
  // it goes, so the panel falls straight down.
  const { isMounted, styles: transitionStyles } = useTransitionStyles(context, {
    duration: { open: 640, close: 320 },
    initial: { opacity: 0, transform: 'translateY(320px)' },
    open: { opacity: 1, transform: 'translateY(0)' },
    common: {
      transitionTimingFunction: open ? SPRING : 'cubic-bezier(0.55, 0, 1, 0.45)',
    },
  })
  const { styles: overlayStyles } = useTransitionStyles(context, {
    duration: { open: 250, close: 320 },
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
            // The overlay scrolls by default, and the panel overhangs its foot while rising in.
            style={{ ...overlayStyles, overflow: 'hidden' }}
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

/** How long typing has to pause before the results follow it. */
const DEBOUNCE = 160

/** The results on screen, the query they answer, and which of them were not on screen before. */
interface Shown {
  query: string
  groups: SearchGroup[]
  fresh: ReadonlySet<string>
}

const hitKey = (hit: SearchHit) => `${hit.slug}#${hit.id ?? ''}`
const groupKey = (group: SearchGroup) => `page:${group.entry.page.slug}`

/** Every result and page heading in `groups`, in the order they are drawn. */
function keysOf(groups: SearchGroup[]): string[] {
  return groups.flatMap((group) => [groupKey(group), ...group.hits.map(hitKey)])
}

/** The next results to show, marking as fresh those the previous results did not have. */
function nextShown(previous: Shown, query: string, groups: SearchGroup[]): Shown {
  const before = new Set(keysOf(previous.groups))
  return { query, groups, fresh: new Set(keysOf(groups).filter((key) => !before.has(key))) }
}

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
  const marker = useRef<HTMLDivElement>(null)
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
  const [shown, setShown] = useState<Shown>({ query: '', groups: [], fresh: new Set() })

  // The results follow the query once typing pauses, and at once when it is cleared.
  useEffect(() => {
    if (index.state !== 'ready') return
    const timer = window.setTimeout(
      () => {
        setShown((previous) => nextShown(previous, trimmed, trimmed ? index.search(trimmed) : []))
      },
      trimmed ? DEBOUNCE : 0,
    )
    return () => {
      window.clearTimeout(timer)
    }
  }, [index, trimmed])

  const { groups } = shown
  const hits = groups.flatMap((group) => group.hits)
  // Only results that were not already on screen rise in, one after another.
  const freshOrder = new Map(
    keysOf(groups)
      .filter((key) => shown.fresh.has(key))
      .map((key, order) => [key, order]),
  )
  const current = Math.min(active, hits.length - 1)

  useEffect(() => {
    options.current[current]?.scrollIntoView({ block: 'nearest' })
  }, [current])

  // Puts the highlight over the selected row. Its first placement is instant, so
  // it appears where the selection is rather than sliding in from the top.
  useLayoutEffect(() => {
    const highlight = marker.current
    if (!highlight) return
    const row = options.current[current]
    if (!row) {
      highlight.style.opacity = '0'
      return
    }
    const appearing = highlight.style.opacity !== '1'
    if (appearing) highlight.style.transition = 'none'
    highlight.style.transform = `translateY(${row.offsetTop}px)`
    highlight.style.height = `${row.offsetHeight}px`
    highlight.style.opacity = '1'
    if (appearing) {
      highlight.getBoundingClientRect()
      highlight.style.transition = ''
    }
  }, [current, groups])

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
      // Enter pressed before the results have caught up opens the best match for what was typed.
      const caughtUp = shown.query === trimmed || index.state !== 'ready'
      const hit = caughtUp ? hits[current] : index.search(trimmed)[0]?.hits[0]
      if (!hit) return
      event.preventDefault()
      open(hit)
    }
  }

  const optionId = (position: number) => `${listId}-${position}`
  // Where each page's hits start in the one list the arrow keys move through.
  const firsts = groups.map((_, at) => groups.slice(0, at).reduce((total, group) => total + group.hits.length, 0))

  return (
    <div className="relative flex max-h-[min(680px,calc(100dvh-min(12vh,112px)-32px))] flex-col overflow-hidden rounded-[16px] border border-line-3 bg-surface-2 shadow-[0_32px_80px_-28px_color-mix(in_srgb,var(--color-shade)_85%,transparent)] light:border-pane-edge light:bg-surface light:shadow-pane">
      {/* A coral hairline along the top edge, brightest in the middle, drawn outward once the panel has landed. */}
      <div aria-hidden className="pointer-events-none absolute inset-x-10 top-0 h-px animate-[search-line_700ms_var(--ease-glide)_140ms_both] bg-linear-to-r from-transparent via-coral/60 to-transparent" />

      <div className="flex h-16 flex-none items-center gap-3.5 border-b border-line-2 px-5">
        <span className="inline-grid animate-[search-pop_520ms_var(--ease-glide)_90ms_both] text-coral">
          <SearchIcon size={18} />
        </span>
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
          className="h-full min-w-0 flex-1 bg-transparent text-[18px] text-cream outline-none placeholder:text-umber [&::-webkit-search-cancel-button]:hidden"
        />
        <button
          type="button"
          onClick={onClose}
          className="flex-none cursor-pointer rounded-[5px] border border-line-3 bg-ink px-1.5 py-1 font-mono text-[11.5px] leading-none font-medium text-umber hover:border-line-5 hover:text-cream light:bg-surface-2"
        >
          Esc
        </button>
      </div>

      {/*
        What is on screen answers the last search, not the text being typed, so it
        stays until the new results replace it rather than leaving the panel empty.
      */}
      <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain">
        {/*
          Clips what is still rising in, so it never counts as overflow and brings up
          the scrollbar for a moment. A long list of results still scrolls.
        */}
        <div className="overflow-clip">
          {index.state === 'failed' ? (
            <Notice title="The search index could not be loaded.">Check your connection, then close search and try again.</Notice>
          ) : trimmed && index.state === 'loading' ? (
            <p className="animate-pulse px-5 py-12 text-center text-[15px] leading-normal text-taupe">Loading the search index…</p>
          ) : !shown.query ? (
            <p className="animate-[search-rise_300ms_var(--ease-glide)_120ms_both] px-5 py-10 text-center text-[15px] leading-normal text-taupe">Search every page of the docs, prose and code alike.</p>
          ) : !groups.length ? (
            <Notice title={`No results for “${shown.query}”`}>Check the spelling, or try fewer or different words.</Notice>
          ) : (
            <div
              id={listId}
              role="listbox"
              aria-label="Results"
              className="relative grid gap-2 px-2.5 pt-1 pb-3"
            >
              {/*
                One highlight for the whole list, which slides from row to row as the
                selection moves. Its coral bar is straight, and the rounded corners clip it.
              */}
              <div
                ref={marker}
                aria-hidden
                className="pointer-events-none absolute top-0 right-2.5 left-2.5 overflow-hidden rounded-[10px] bg-cream/6 opacity-0 transition-[transform,height,opacity] duration-220 ease-glide before:absolute before:inset-y-0 before:left-0 before:w-[4px] before:bg-coral light:bg-shade/5"
              />
              {groups.map((group, groupAt) => {
                const groupLabel = group.entry.groups.at(-1)?.label
                return (
                  <div key={group.entry.page.slug} role="group" aria-label={group.entry.page.title}>
                    <div
                      style={entrance(freshOrder.get(groupKey(group)))}
                      className={`flex min-w-0 items-center gap-2 px-3 pt-4 pb-2 text-[13px] leading-none ${RISE(freshOrder.has(groupKey(group)))}`}
                    >
                      <span aria-hidden className="size-1.5 flex-none bg-coral" />
                      {groupLabel && (
                        <>
                          <span className="hidden flex-none text-umber sm:inline">{groupLabel}</span>
                          <span aria-hidden className="hidden text-gutter sm:inline">/</span>
                        </>
                      )}
                      <span className="min-w-0 truncate font-semibold text-sand">{group.entry.page.title}</span>
                    </div>
                    <div className="grid gap-0.5">
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
                            style={entrance(freshOrder.get(hitKey(hit)))}
                            className={RISE(freshOrder.has(hitKey(hit)))}
                            onPointerMove={() => {
                              if (!on) setActive(at)
                            }}
                          >
                            <DocLink
                              slug={hit.slug}
                              hash={hit.id}
                              onClick={onClose}
                              className="relative flex items-start gap-3 rounded-[10px] py-2.5 pr-3 pl-3"
                            >
                              <span
                                aria-hidden
                                className={`mt-px grid size-7.5 flex-none place-items-center rounded-[7px] border font-mono transition-colors duration-200 text-[14px] leading-none font-semibold ${
                                  on ? 'border-coral/50 bg-coral/10 text-coral' : 'border-line-3 bg-ink text-umber light:bg-surface-2'
                                }`}
                              >
                                {hit.heading ? '#' : <PageIcon />}
                              </span>
                              <span className="grid min-w-0 flex-1 gap-1">
                                <span className="truncate text-[15.5px] leading-[1.35] font-medium text-cream">
                                  {hit.heading ? <Highlighted fragments={hit.heading} /> : 'Overview'}
                                </span>
                                {hit.snippet.length > 0 &&
                                  (hit.code ? (
                                    <span className="line-clamp-2 rounded-[6px] border border-line-2 bg-ink px-2 py-1 font-mono text-[12.5px] leading-[1.55] text-fawn light:bg-pane">
                                      <Highlighted fragments={hit.snippet} />
                                    </span>
                                  ) : (
                                    <span className="line-clamp-2 text-[14px] leading-[1.5] text-taupe">
                                      <Highlighted fragments={hit.snippet} />
                                    </span>
                                  ))}
                              </span>
                              <span
                                aria-hidden
                                className={`mt-1.5 flex-none font-mono text-[13px] leading-none text-umber transition-opacity duration-200 ${on ? 'opacity-100' : 'opacity-0'}`}
                              >
                                ↵
                              </span>
                            </DocLink>
                          </div>
                        )
                      })}
                    </div>
                  </div>
                )
              })}
            </div>
          )}
        </div>
      </div>

      <div className="hidden flex-none items-center gap-4 border-t border-line-2 bg-ink/50 px-5 py-3 text-[12.5px] leading-none text-umber light:bg-surface-2/60 sm:flex">
        <span className="flex items-center gap-1.5">
          <Key>↑</Key>
          <Key>↓</Key>
          to move
        </span>
        <span className="flex items-center gap-1.5">
          <Key>↵</Key>
          to open
        </span>
        <span className="flex items-center gap-1.5">
          <Key>esc</Key>
          to close
        </span>
        {hits.length > 0 && (
          <span className="ml-auto">
            {hits.length} {hits.length === 1 ? 'result' : 'results'}
          </span>
        )}
      </div>
    </div>
  )
}

/**
 * The rise a fresh result or page heading enters with. Results that stay on
 * screen between searches have none, so a move in the list never replays it.
 */
const RISE = (fresh: boolean) => (fresh ? 'animate-[search-rise_460ms_var(--ease-glide)_both]' : '')

/** How long a fresh result waits before rising, so new results arrive one after another. */
function entrance(order: number | undefined) {
  return order === undefined ? undefined : { animationDelay: `${Math.min(order, 10) * 45}ms` }
}

function Highlighted({ fragments }: { fragments: Fragment[] }) {
  return fragments.map((fragment, at) =>
    fragment.match ? (
      <mark key={at} className="rounded-[3px] bg-coral/14 box-decoration-clone px-[2px] font-semibold text-coral">
        {fragment.text}
      </mark>
    ) : (
      fragment.text
    ),
  )
}

function Notice({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div className="grid animate-[search-rise_300ms_var(--ease-glide)_both] justify-items-center gap-2 px-5 py-12 text-center">
      <span className="mb-1 grid size-10 place-items-center rounded-full border border-line-3 text-umber">
        <SearchIcon size={17} />
      </span>
      <p className="text-[15.5px] leading-normal font-medium text-cream">{title}</p>
      <p className="text-[14.5px] leading-normal text-taupe">{children}</p>
    </div>
  )
}

function Key({ children }: { children: ReactNode }) {
  return (
    <kbd className="grid h-5.5 min-w-5.5 place-items-center rounded-[5px] border border-line-3 bg-ink px-1 font-mono text-[11.5px] leading-none text-sand light:bg-surface">
      {children}
    </kbd>
  )
}

function SearchIcon({ size = 16 }: { size?: number }) {
  return (
    <svg aria-hidden width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" className="flex-none">
      <circle cx="11" cy="11" r="7" />
      <path d="M20 20l-3.6-3.6" />
    </svg>
  )
}

/** A page with its corner turned, for the opening of a page rather than one of its headings. */
function PageIcon() {
  return (
    <svg aria-hidden width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" />
      <path d="M14 3v5h5M9 13h6M9 17h4" />
    </svg>
  )
}
