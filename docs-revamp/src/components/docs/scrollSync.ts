import type { EditorView } from '@codemirror/view'
import { useEffect, useState } from 'react'

/** A point where a line of the Markdown and a height on the page are known to meet. */
interface Anchor {
  line: number
  /** Distance from the top of the document, in pixels. */
  top: number
}

/**
 * The input that shows which side the reader is working in. Scrolling by
 * wheel, touch, scrollbar or keyboard each begins with one of these.
 */
const INTENT_EVENTS = ['wheel', 'touchstart', 'pointerdown', 'keydown'] as const

const PREFERENCE = 'docs-editor-sync-scroll'

function clamp(value: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, value))
}

/**
 * The bottom of the bars stuck to the top of the window. The page is read from
 * just below them, so that is the height lined up with the editor's top line.
 */
function readingOffset(): number {
  let bottom = 0
  for (const bar of document.querySelectorAll('[data-sticky-bar]')) {
    const box = bar.getBoundingClientRect()
    if (box.height > 0) bottom = Math.max(bottom, box.bottom)
  }
  return bottom
}

/**
 * Every block in the article with the line it came from, in order. The page
 * scrolled to its top, read from `offset` down, meets line 1, where the
 * frontmatter the title is drawn from begins; the article's end meets the line
 * after the last. A block that would run
 * the lines or the heights backwards, such as a list item on its list's own
 * line, is skipped, so both always increase.
 */
function articleAnchors(article: HTMLElement, lineCount: number, offset: number): Anchor[] {
  const anchors: Anchor[] = [{ line: 1, top: offset }]
  for (const block of article.querySelectorAll<HTMLElement>('[data-source-line]')) {
    const line = Number(block.dataset.sourceLine)
    const top = block.getBoundingClientRect().top + window.scrollY
    const last = anchors[anchors.length - 1]
    if (!last || !Number.isFinite(line) || line <= last.line || top <= last.top) continue
    anchors.push({ line, top })
  }
  const end = article.getBoundingClientRect().bottom + window.scrollY
  const last = anchors[anchors.length - 1]
  if (last && end > last.top && lineCount + 1 > last.line) anchors.push({ line: lineCount + 1, top: end })
  return anchors
}

/** The fractional line of the Markdown shown at `top` on the page, between the anchors either side of it. */
function lineAtTop(anchors: readonly Anchor[], top: number): number {
  for (let i = anchors.length - 1; i >= 0; i--) {
    const from = anchors[i]
    if (!from || from.top > top) continue
    const to = anchors[i + 1]
    if (!to) return from.line
    return from.line + ((top - from.top) / (to.top - from.top)) * (to.line - from.line)
  }
  return 1
}

/** The height on the page where a fractional line of the Markdown is shown. */
function topOfLine(anchors: readonly Anchor[], line: number): number {
  for (let i = anchors.length - 1; i >= 0; i--) {
    const from = anchors[i]
    if (!from || from.line > line) continue
    const to = anchors[i + 1]
    if (!to) return from.top
    return from.top + ((line - from.line) / (to.line - from.line)) * (to.top - from.top)
  }
  return 0
}

/**
 * The fractional line at the top of the editor's viewport: the line there,
 * plus how far through its wrapped height the top falls.
 */
function editorTopLine(view: EditorView): number {
  const height = Math.max(0, view.scrollDOM.scrollTop - view.documentPadding.top)
  const block = view.lineBlockAtHeight(height)
  const line = view.state.doc.lineAt(block.from).number
  return line + clamp((height - block.top) / block.height, 0, 1)
}

/** The editor's scroll position at which a fractional line starts at its top. */
function editorTopOf(view: EditorView, line: number): number {
  const { doc } = view.state
  const whole = clamp(Math.floor(line), 1, doc.lines)
  const block = view.lineBlockAt(doc.line(whole).from)
  return block.top + clamp(line - whole, 0, 1) * block.height + view.documentPadding.top
}

/**
 * How many times the editor re-measures and re-aims after a move. Each round
 * draws lines whose heights were only estimated; a few settle any page.
 */
const SETTLE_ROUNDS = 4

/**
 * Scrolls the editor so a fractional line starts at its top. CodeMirror only
 * estimates the height of a wrapped line it has not drawn, so the first move
 * can fall short or long. Each measure afterwards draws the lines now in view
 * and gives their real heights, and the position is worked out again from
 * them, until it stops moving.
 */
function scrollEditorToLine(view: EditorView, line: number, round = 0): void {
  view.scrollDOM.scrollTop = editorTopOf(view, line)
  if (round >= SETTLE_ROUNDS) return
  view.requestMeasure({
    key: 'docs-scroll-sync',
    read: () => editorTopOf(view, line),
    write: (top) => {
      if (Math.abs(view.scrollDOM.scrollTop - top) > 1) scrollEditorToLine(view, line, round + 1)
    },
  })
}

/**
 * Keeps the editor's scroll position and the article's in step while `enabled`.
 * The side the reader last touched leads, and the other is moved to show the
 * same part of the page. Only the leader's scrolling is followed, so the
 * scroll a follower makes on being moved is never taken for the reader's and
 * sent back, however late it arrives. When it starts, the editor moves to
 * where the article is.
 */
export function useScrollSync(view: EditorView | null, enabled: boolean): void {
  useEffect(() => {
    const article = document.querySelector<HTMLElement>('main article')
    if (!view || !article || !enabled) return

    const pane = view.dom.closest('section') ?? view.dom
    let leader: 'editor' | 'article' = 'article'
    let frame = 0

    const claim = (event: Event) => {
      leader = event.target instanceof Node && pane.contains(event.target) ? 'editor' : 'article'
    }

    const later = (step: () => void) => {
      cancelAnimationFrame(frame)
      frame = requestAnimationFrame(step)
    }

    const followEditor = () => {
      const offset = readingOffset()
      const anchors = articleAnchors(article, view.state.doc.lines, offset)
      const top = topOfLine(anchors, editorTopLine(view)) - offset
      window.scrollTo({ top: Math.max(0, top), behavior: 'instant' })
    }

    const followArticle = () => {
      const offset = readingOffset()
      const anchors = articleAnchors(article, view.state.doc.lines, offset)
      scrollEditorToLine(view, lineAtTop(anchors, window.scrollY + offset))
    }

    const onEditorScroll = () => {
      if (leader === 'editor') later(followEditor)
    }
    const onArticleScroll = () => {
      if (leader === 'article') later(followArticle)
    }

    for (const type of INTENT_EVENTS) window.addEventListener(type, claim, { capture: true, passive: true })
    view.scrollDOM.addEventListener('scroll', onEditorScroll, { passive: true })
    window.addEventListener('scroll', onArticleScroll, { passive: true })
    later(followArticle)

    return () => {
      cancelAnimationFrame(frame)
      for (const type of INTENT_EVENTS) window.removeEventListener(type, claim, { capture: true })
      view.scrollDOM.removeEventListener('scroll', onEditorScroll)
      window.removeEventListener('scroll', onArticleScroll)
    }
  }, [view, enabled])
}

/** Whether scroll syncing is on, remembered in this browser. It starts on. */
export function useSyncPreference(): [boolean, (on: boolean) => void] {
  const [on, setOn] = useState(() => {
    try {
      return localStorage.getItem(PREFERENCE) !== 'off'
    } catch {
      return true
    }
  })

  useEffect(() => {
    try {
      localStorage.setItem(PREFERENCE, on ? 'on' : 'off')
    } catch {
      // Storage can be unavailable; the choice then lasts until the page reloads.
    }
  }, [on])

  return [on, setOn]
}
