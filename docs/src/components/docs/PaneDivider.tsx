import type { KeyboardEvent, PointerEvent } from 'react'
import { useEffect, useRef, useState } from 'react'

/** From this width up the editor sits beside the article; below it, it rises over the lower part of the screen. */
const SIDE_BY_SIDE = '(min-width: 990px)'

/** The narrowest the editor can be made beside the article, and the least room the article is always left. */
const MIN_WIDTH = 360
const MIN_ARTICLE = 420

/** The shortest the editor can be made below 990px, and the least of the page always left above it. */
const MIN_HEIGHT = 160
const MIN_ABOVE = 140

/** How far one arrow-key press moves the divider. */
const STEP = 24

const STORAGE = { width: 'docs-editor-width', height: 'docs-editor-height' } as const

type Axis = keyof typeof STORAGE

/** The CSS variable each size is written to, on the element holding the article and the editor. */
const VARIABLE: Record<Axis, string> = { width: '--editor-width', height: '--editor-height' }

function clamp(value: number, min: number, max: number): number {
  return Math.round(Math.min(Math.max(value, min), Math.max(min, max)))
}

/**
 * The sizes the divider can set. Beside the article the editor runs to the
 * layout's right edge, which the window's scrollbar sits outside of, so the
 * room is measured from the layout rather than the window.
 */
function limitsFor(axis: Axis, layout: HTMLElement | null): [number, number] {
  return axis === 'width'
    ? [MIN_WIDTH, (layout?.clientWidth ?? window.innerWidth) - MIN_ARTICLE]
    : [MIN_HEIGHT, window.innerHeight - MIN_ABOVE]
}

/**
 * The value written to the size's CSS variable. It is capped again in CSS, so
 * a size chosen in a larger window still leaves the article its room when the
 * window shrinks.
 */
function cssSize(axis: Axis, size: number): string {
  return axis === 'width'
    ? `min(${size}px, calc(100% - ${MIN_ARTICLE}px))`
    : `min(${size}px, calc(100dvh - ${MIN_ABOVE}px))`
}

function readStored(axis: Axis): number | undefined {
  try {
    const value = Number(localStorage.getItem(STORAGE[axis]))
    return Number.isFinite(value) && value > 0 ? value : undefined
  } catch {
    return undefined
  }
}

function store(axis: Axis, size: number | undefined): void {
  try {
    if (size === undefined) localStorage.removeItem(STORAGE[axis])
    else localStorage.setItem(STORAGE[axis], String(size))
  } catch {
    // Storage can be unavailable; the size then lasts until the page reloads.
  }
}

function useSideBySide(): boolean {
  const [matches, setMatches] = useState(() => window.matchMedia(SIDE_BY_SIDE).matches)
  useEffect(() => {
    const query = window.matchMedia(SIDE_BY_SIDE)
    const onChange = () => {
      setMatches(query.matches)
    }
    query.addEventListener('change', onChange)
    return () => {
      query.removeEventListener('change', onChange)
    }
  }, [])
  return matches
}

/**
 * The handle between the article and the editor. Beside the article it sets
 * the editor's width; below 990px, where the editor rises over the page, it
 * sets its height. It can be dragged, or focused and moved with the arrow keys,
 * and a double-click puts the editor back to its default size. The size is
 * remembered in this browser.
 *
 * The sizes are CSS variables on the element marked `data-editor-layout`, which
 * holds the article and the editor. While dragging it writes the size straight
 * to that variable rather than to React state, so the article reflows as the
 * divider moves without re-rendering.
 */
export function PaneDivider() {
  const handle = useRef<HTMLDivElement>(null)
  const layout = () => handle.current?.closest<HTMLElement>('[data-editor-layout]') ?? null
  const sideBySide = useSideBySide()
  const axis: Axis = sideBySide ? 'width' : 'height'
  const [size, setSize] = useState<Record<Axis, number | undefined>>(() => ({
    width: readStored('width'),
    height: readStored('height'),
  }))
  const [dragging, setDragging] = useState(false)

  useEffect(() => {
    const element = handle.current?.closest<HTMLElement>('[data-editor-layout]')
    if (!element) return
    for (const each of ['width', 'height'] as const) {
      const value = size[each]
      if (value === undefined) element.style.removeProperty(VARIABLE[each])
      else element.style.setProperty(VARIABLE[each], cssSize(each, value))
    }
  }, [size])

  const commit = (next: number | undefined) => {
    setSize((current) => ({ ...current, [axis]: next }))
    store(axis, next)
  }

  /** The size the pointer at this position asks for: the editor runs from the pointer to the window's edge. */
  const sizeAt = (event: PointerEvent) => {
    const element = layout()
    const [min, max] = limitsFor(axis, element)
    const right = element?.getBoundingClientRect().right ?? window.innerWidth
    return clamp(axis === 'width' ? right - event.clientX : window.innerHeight - event.clientY, min, max)
  }

  const onPointerDown = (event: PointerEvent<HTMLDivElement>) => {
    if (event.button !== 0) return
    event.preventDefault()
    event.currentTarget.setPointerCapture(event.pointerId)
    setDragging(true)
  }

  const onPointerMove = (event: PointerEvent<HTMLDivElement>) => {
    if (!dragging) return
    layout()?.style.setProperty(VARIABLE[axis], cssSize(axis, sizeAt(event)))
  }

  const onPointerUp = (event: PointerEvent<HTMLDivElement>) => {
    if (!dragging) return
    setDragging(false)
    event.currentTarget.releasePointerCapture(event.pointerId)
    commit(sizeAt(event))
  }

  const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    // Beside the article the editor is on the right, so moving the divider left widens it; below, moving it up heightens it.
    const grow = axis === 'width' ? 'ArrowLeft' : 'ArrowUp'
    const shrink = axis === 'width' ? 'ArrowRight' : 'ArrowDown'
    if (event.key !== grow && event.key !== shrink) return
    event.preventDefault()
    const pane = layout()?.querySelector<HTMLElement>('[data-editor-pane]')
    const current = size[axis] ?? (axis === 'width' ? pane?.offsetWidth : pane?.offsetHeight) ?? MIN_WIDTH
    const [min, max] = limitsFor(axis, layout())
    commit(clamp(current + (event.key === grow ? STEP : -STEP), min, max))
  }

  // Keep the whole page from selecting text or showing other cursors while the divider is dragged.
  useEffect(() => {
    if (!dragging) return
    const { style } = document.body
    style.userSelect = 'none'
    style.cursor = axis === 'width' ? 'col-resize' : 'row-resize'
    return () => {
      style.userSelect = ''
      style.cursor = ''
    }
  }, [dragging, axis])

  // Read from the window alone: the handle is not mounted yet on the first render, and these only describe the range to assistive tech.
  const [min, max] = limitsFor(axis, null)

  return (
    <div
      ref={handle}
      role="separator"
      tabIndex={0}
      aria-label="Resize the editor"
      aria-orientation={sideBySide ? 'vertical' : 'horizontal'}
      aria-valuemin={min}
      aria-valuemax={max}
      aria-valuenow={size[axis]}
      title="Drag to resize. Double-click to reset."
      onPointerDown={onPointerDown}
      onPointerMove={onPointerMove}
      onPointerUp={onPointerUp}
      onPointerCancel={onPointerUp}
      onDoubleClick={() => {
        commit(undefined)
      }}
      onKeyDown={onKeyDown}
      className={`group absolute z-10 touch-none outline-none ${
        sideBySide ? 'inset-y-0 -left-1 w-2 cursor-col-resize' : 'inset-x-0 -top-1 h-2 cursor-row-resize'
      }`}
    >
      <div
        className={`pointer-events-none absolute transition-colors duration-150 group-hover:bg-coral/70 group-focus-visible:bg-coral ${
          dragging ? 'bg-coral' : 'bg-transparent'
        } ${sideBySide ? 'inset-y-0 left-[3px] w-0.5' : 'inset-x-0 top-[3px] h-0.5'}`}
      />
      {!sideBySide && (
        <div className="pointer-events-none absolute top-[7px] left-1/2 h-1 w-10 -translate-x-1/2 rounded-full bg-line-5" />
      )}
    </div>
  )
}
