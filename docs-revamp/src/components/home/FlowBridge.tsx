import { useLayoutEffect, useRef } from 'react'
import { CORNER_RADIUS, bend, layoutBox } from './flow'
import { FlowSegment } from './FlowSegment'

/**
 * The bridge's corners follow the page's width. It crosses far more ground than
 * the hero's short connectors, so at `WIDE` and beyond it takes a wide, slow
 * turn of `LARGEST_CORNER`. Below that the turn tightens in step with the page,
 * falling towards the connectors' own radius at `NARROW` and never below it.
 */
const LARGEST_CORNER = 96
const WIDE = 1440
const NARROW = 0

function cornerRadius(width: number): number {
  const progress = Math.min(1, Math.max(0, (width - NARROW) / (WIDE - NARROW)))
  return CORNER_RADIUS + (LARGEST_CORNER - CORNER_RADIUS) * progress
}

/**
 * The dotted path from the bottom of the hero's output panel to the first node
 * of the timeline below it, so the question the reader picked runs straight on
 * into the three pieces. It drops out of the panel, turns in the space above
 * the next section's heading, and runs down the timeline's rail.
 *
 * It spans two sections, so it is drawn over both from their shared parent,
 * which must be positioned. The ends are marked in the markup — the panel with
 * `data-flow-start`, the node with `data-flow-end`, the heading it passes with
 * `data-flow-heading` — and measured, since all three move as the page wraps,
 * the panel changes height with the question, and the timeline places its
 * nodes after it lays out.
 */
export function FlowBridge() {
  const svg = useRef<SVGSVGElement>(null)

  useLayoutEffect(() => {
    const frame = svg.current?.parentElement
    const start = frame?.querySelector<HTMLElement>('[data-flow-start]')
    const end = frame?.querySelector<HTMLElement>('[data-flow-end]')
    const heading = frame?.querySelector<HTMLElement>('[data-flow-heading]')
    const section = heading?.closest('section')
    const paths = svg.current?.querySelectorAll('path')
    if (!frame || !start || !end || !heading || !section || !paths) return

    const draw = () => {
      const box = layoutBox(frame)
      const panel = layoutBox(start)
      const node = layoutBox(end)
      // The path turns halfway between the top of the timeline's section and its heading.
      const turn = (layoutBox(section).top + layoutBox(heading).top) / 2 - box.top

      const from = { x: panel.left + panel.width / 2 - box.left, y: panel.top + panel.height - box.top }
      const to = { x: node.left + node.width / 2 - box.left, y: node.top - box.top }

      const d = bend(from, to, turn, cornerRadius(box.width))
      for (const path of paths) path.setAttribute('d', d)
    }

    draw()
    void document.fonts.ready.then(draw)

    const resized = new ResizeObserver(draw)
    resized.observe(frame)
    resized.observe(start)
    // The timeline places its nodes by setting their `top` after it measures. The
    // choreography animates their transform through the same style attribute,
    // which moves nothing that is measured here, so only a new `top` redraws.
    let placedAt = end.style.top
    const moved = new MutationObserver(() => {
      if (end.style.top === placedAt) return
      placedAt = end.style.top
      draw()
    })
    moved.observe(end, { attributes: true, attributeFilter: ['style'] })

    return () => {
      resized.disconnect()
      moved.disconnect()
    }
  }, [])

  return (
    <svg
      ref={svg}
      data-choreo="bridge"
      aria-hidden="true"
      className="pointer-events-none absolute inset-0 z-1 size-full overflow-visible"
    >
      <FlowSegment />
    </svg>
  )
}
