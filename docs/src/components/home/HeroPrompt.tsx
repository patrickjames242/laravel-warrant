import { useEffect, useState } from 'react'

/**
 * What the prompt types: one sentence that carries on from the headline's first
 * line, with an ending for each of the hero's three questions, in the order its
 * tabs list them. Every ending follows the same opening.
 */
const OPENING = 'Use it to '
const ENDINGS = ['filter queries.', 'check a row.', 'list abilities.']
const LINES = ENDINGS.map((ending) => OPENING + ending)
const LONGEST = LINES.reduce((longest, line) => (line.length > longest.length ? line : longest))

/** How long the prompt waits for the hero's intro before typing. */
const START_DELAY = 750
/** How long each letter takes to type, and to erase. */
const LETTER = 55
const ERASE = 28
/** How long a typed line stays before its ending is erased, and the pause before the next ending begins. */
const HOLD = 1900
const BETWEEN = 350

function prefersReducedMotion(): boolean {
  return typeof matchMedia !== 'undefined' && matchMedia('(prefers-reduced-motion: reduce)').matches
}

/** Where the prompt is: which line, how much of it is typed, and whether its ending is being erased. */
interface Typing {
  at: number
  typed: number
  erasing: boolean
  begun: boolean
}

/**
 * The prompt's next state, and how long to wait before moving to it. An ending
 * is erased back to the opening, which stays while the next ending is typed.
 */
function advance(state: Typing): [Typing, number] {
  const line = LINES[state.at] ?? ''
  if (!state.erasing) {
    if (state.typed < line.length) {
      return [{ ...state, typed: state.typed + 1, begun: true }, state.begun ? LETTER : START_DELAY]
    }
    return [{ ...state, erasing: true }, HOLD]
  }
  if (state.typed > OPENING.length) return [{ ...state, typed: state.typed - 1 }, ERASE]
  return [{ ...state, at: (state.at + 1) % LINES.length, erasing: false }, BETWEEN]
}

/**
 * The second line of the hero's headline: a terminal prompt that carries the
 * first line on, typing what the rule can then be used for. Each ending is
 * erased back to the shared opening and the next typed in its place. A
 * terminal's block cursor sits on the last letter typed. Under reduced motion
 * the first line is shown whole and stays.
 *
 * The prompt is sized to the longest line, laid out invisibly underneath, so
 * it keeps one width as endings are typed and erased. It is hidden from
 * assistive technology, which reads the headline's own text instead.
 */
export function HeroPrompt() {
  const [still] = useState(prefersReducedMotion)
  const [state, setState] = useState<Typing>(() => ({
    at: 0,
    typed: still ? (LINES[0]?.length ?? 0) : 0,
    erasing: false,
    begun: still,
  }))

  useEffect(() => {
    if (still) return
    const [next, delay] = advance(state)
    const timer = window.setTimeout(() => {
      setState(next)
    }, delay)
    return () => {
      window.clearTimeout(timer)
    }
  }, [state, still])

  const line = LINES[state.at] ?? ''
  // Everything typed but the last letter, which the cursor sits on.
  const before = line.slice(0, Math.max(state.typed - 1, 0))

  return (
    <span
      aria-hidden
      className="inline-flex items-baseline gap-[0.6em] rounded-[0.55em] border border-line-5 bg-surface-3 px-[0.95em] py-[0.62em] text-left font-prompt text-[clamp(19px,2.5vw,30px)] leading-none font-semibold tracking-normal text-coral shadow-[inset_0_1px_0_rgb(255_255_255/0.06),0_14px_36px_-14px_rgb(0_0_0/0.7)] light:border-pane-edge light:bg-pane light:shadow-pane"
    >
      {/*
        A prompt chevron, centred on the lowercase letters' middle, since the line
        is mostly lowercase and a capital-height chevron reads as too big and too high.
      */}
      <svg viewBox="0 0 10 16" className="h-[0.56em] w-[0.35em] flex-none -translate-y-[0.02em] overflow-visible">
        <path d="M2 1.5 L8.5 8 L2 14.5" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" />
      </svg>
      <span className="grid">
        {/* Sized to the longest line: every letter but its last, then the cursor's cell holding it. */}
        <span className="invisible col-start-1 row-start-1 whitespace-pre">
          {LONGEST.slice(0, -1)}
          <Cursor letter={LONGEST.at(-1)} />
        </span>
        <span className="col-start-1 row-start-1 whitespace-pre">
          {before}
          <Cursor letter={state.typed ? line[state.typed - 1] : undefined} />
        </span>
      </span>
    </span>
  )
}

/**
 * A terminal's block cursor on one letter: a solid coral cell with the letter
 * showing through in the ground colour. As it blinks off the letter shows in
 * coral again. With no letter yet it is an empty cell.
 */
function Cursor({ letter }: { letter?: string }) {
  return (
    <span className="inline-block w-[1ch] animate-[cursor-blink_1.05s_step-end_infinite] bg-coral text-center leading-[1.2em] text-ink [--cursor-off:var(--color-coral)]">
      {letter === ' ' || letter === undefined ? ' ' : letter}
    </span>
  )
}
