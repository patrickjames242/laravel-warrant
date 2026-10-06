import { useId } from 'react'

/**
 * The length every segment's mask treats its path as, whatever its real length.
 * Offsetting the mask's dash by this much hides the line and by nothing shows it.
 * It is large so that the whole-pixel steps an animation writes are each a sliver
 * of the line, not half of it.
 */
export const REVEAL_LENGTH = 1000

/**
 * One stretch of the coral dotted line, drawn inside an `<svg>`.
 *
 * The dots are the visible path's dash pattern, so the path cannot also be
 * revealed by animating its own dashes. It is masked instead, by a copy of the
 * same path whose single dash covers its whole length: with `pathLength` set to
 * `REVEAL_LENGTH`, a `stroke-dashoffset` of that much hides the line and 0 shows
 * all of it, whatever its real length or shape. The mask's path carries
 * `data-flow-reveal`, which is what a question's redraw animates; with no
 * offset set, the line shows in full.
 *
 * `d` may be left out and set on both paths directly, for a line whose shape is
 * measured rather than rendered.
 */
export function FlowSegment({ d }: { d?: string }) {
  const id = `flow-${useId().replace(/[^\w-]/g, '')}`

  return (
    <>
      <mask id={id} maskUnits="userSpaceOnUse" x="-50%" y="-50%" width="200%" height="200%">
        <path
          d={d}
          data-flow-reveal
          pathLength={REVEAL_LENGTH}
          strokeDasharray={`${REVEAL_LENGTH} ${REVEAL_LENGTH}`}
          className="fill-none stroke-white [stroke-width:16]"
        />
      </mask>
      <path d={d} mask={`url(#${id})`} className="flow-path" />
    </>
  )
}
