import { useEffect, useState } from 'react'

const NAME = 'Warrant'
const REST = ' for Laravel'
const FULL = NAME + REST

/** How long the tagline waits for the hero's intro before typing, and how long each letter takes. */
const START_DELAY = 650
const LETTER = 55

function prefersReducedMotion(): boolean {
  return typeof matchMedia !== 'undefined' && matchMedia('(prefers-reduced-motion: reduce)').matches
}

/**
 * The line above the hero's headline, set as a prompt in a code chip that
 * types itself out after the intro. A terminal's block cursor sits on each
 * letter as it arrives and stays blinking on the last one. Under reduced motion
 * the line is shown whole. The full text is laid out underneath,
 * invisibly, so the chip is its final width from the start and nothing shifts
 * as the letters arrive.
 */
export function HeroTagline() {
  const [typed, setTyped] = useState(() => (prefersReducedMotion() ? FULL.length : 0))

  useEffect(() => {
    if (typed >= FULL.length) return
    const timer = window.setTimeout(
      () => {
        setTyped((count) => count + 1)
      },
      typed === 0 ? START_DELAY : LETTER,
    )
    return () => {
      window.clearTimeout(timer)
    }
  }, [typed])

  // Everything typed but the last letter, which the cursor sits on.
  const before = FULL.slice(0, Math.max(typed - 1, 0))
  const name = before.slice(0, NAME.length)
  const rest = before.slice(NAME.length)

  return (
    <span
      className="inline-flex items-baseline gap-[0.6em] rounded-[13px] border border-line-5 bg-surface-3 px-5.5 py-3.5 shadow-[inset_0_1px_0_rgb(255_255_255/0.06),0_14px_36px_-14px_rgb(0_0_0/0.7)] font-tagline text-[17px] leading-none font-semibold tracking-normal light:border-pane-edge light:bg-pane light:shadow-pane sm:text-[21px]"
    >
      <span className="sr-only">{FULL}</span>
      {/*
        A prompt chevron, centred on the lowercase letters' middle, since the line
        is mostly lowercase and a capital-height chevron reads as too big and too high.
      */}
      <svg aria-hidden viewBox="0 0 10 16" className="h-[0.56em] w-[0.35em] flex-none translate-y-[0.07em] overflow-visible text-coral">
        <path d="M2 1.5 L8.5 8 L2 14.5" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" />
      </svg>
      <span aria-hidden className="grid">
        <span className="invisible col-start-1 row-start-1 whitespace-pre">{FULL}</span>
        <span className="col-start-1 row-start-1 whitespace-pre">
          <span className="text-cream">{name}</span>
          <span className="text-coral">{rest}</span>
          <Cursor letter={typed ? FULL[typed - 1] : undefined} inName={typed <= NAME.length} />
        </span>
      </span>
    </span>
  )
}

/**
 * A terminal's block cursor on one letter: a solid coral cell with the letter
 * showing through in the ground colour. As it blinks off the letter shows in
 * its own colour again. With no letter yet it is an empty cell.
 */
function Cursor({ letter, inName }: { letter?: string; inName: boolean }) {
  return (
    <span
      className={`inline-block w-[1ch] animate-[cursor-blink_1.05s_step-end_infinite] bg-coral text-center leading-[1.2em] text-ink ${
        inName ? '[--cursor-off:var(--color-cream)]' : '[--cursor-off:var(--color-coral)]'
      }`}
    >
      {letter === ' ' || letter === undefined ? '\u00a0' : letter}
    </span>
  )
}
