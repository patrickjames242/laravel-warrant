import { useRouterState } from '@tanstack/react-router'
import { useEffect, useRef } from 'react'

/** How long a load runs before the line starts drawing, so a page that arrives at once shows nothing. */
const DELAY = 150

/**
 * A coral line along the bottom edge of the site header while the next page
 * loads. It slows as it goes, so a long load never reaches the end; when the
 * page arrives it runs on from wherever it got to, across the full width, and
 * fades out.
 *
 * It sits in the root layout rather than the header, because a page that
 * brings its own header would cut the line off before it finished. Its top is
 * the header's height, the nav's `h-18` and its 1px border, so it lies over
 * that border.
 */
export function LoadingLine() {
  const loading = useRouterState({ select: (state) => state.status === 'pending' })
  const line = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const el = line.current
    if (!el) return
    const still = matchMedia('(prefers-reduced-motion: reduce)').matches
    const [running] = el.getAnimations()

    if (loading) {
      running?.cancel()
      const frames = still
        ? [{ transform: 'scaleX(0.9)', opacity: 1 }]
        : [
            { transform: 'scaleX(0)', opacity: 1 },
            { transform: 'scaleX(0.9)', opacity: 1 },
          ]
      el.animate(frames, { duration: 10_000, delay: DELAY, easing: 'cubic-bezier(0.05, 0.7, 0.1, 1)', fill: 'both' })
      return
    }

    if (!running) return
    const drawn = Number(running.currentTime) > DELAY
    const from = getComputedStyle(el).transform
    running.cancel()
    if (!drawn || still) return

    el.animate(
      [
        { transform: from, opacity: 1 },
        { transform: 'scaleX(1)', opacity: 1, offset: 0.5 },
        { transform: 'scaleX(1)', opacity: 0 },
      ],
      { duration: 500, easing: 'ease-out' },
    )
  }, [loading])

  return (
    <div
      ref={line}
      aria-hidden
      className="pointer-events-none fixed inset-x-0 top-[calc(var(--spacing)*18+1px)] z-[60] h-0.5 origin-left -translate-y-full bg-coral opacity-0"
    />
  )
}
