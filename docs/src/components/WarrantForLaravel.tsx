import { useId } from 'react'
import { useElementWidth } from '../lib/useElementWidth'

/**
 * The stamp's width, in pixels, at which the ink filter's measurements are
 * given. The edges' wobble and bleed scale with the stamp either way, so a
 * small stamp's letters stay legible. The pinholes scale up but never down,
 * since a pinhole much under a pixel is lost and the ink reads as flat; a
 * smaller stamp has fewer of them instead.
 */
const INK_WIDTH = 209

/**
 * "Warrant for Laravel": the name in tall condensed capitals, as a tilted rubber
 * stamp in coral ink, with "for Laravel" handwritten beneath its right end on a
 * signature line, so the two read as one stamped and signed warrant. Sized by
 * the font size it is given; the signature never shrinks below a readable size.
 *
 * The stamp's ink is drawn by an SVG filter: its edges wobble and swell as
 * though the ink spread into the paper, and it is speckled with pinholes where
 * the rubber did not pick the ink up. The filter's measurements follow the
 * stamp's width, so it looks equally worn at every size.
 */
export function WarrantForLaravel({ className = '' }: { className?: string }) {
  // React's ids carry punctuation that a CSS url() would need escaped.
  const ink = `stamp-ink-${useId().replace(/[^\w-]/g, '')}`
  const [stampRef, width] = useElementWidth<HTMLSpanElement>(INK_WIDTH)
  const edges = width / INK_WIDTH
  const holes = Math.max(edges, 1)
  // The noise level above which the ink gives way to a pinhole, raised on a smaller stamp.
  const threshold = 0.66 + 0.1 * Math.max(1 - edges, 0)

  return (
    <span className={`inline-flex flex-col items-end gap-[0.22em] leading-none font-extrabold tracking-[-0.02em] whitespace-nowrap ${className}`}>
      <svg aria-hidden className="absolute size-0">
        <filter id={ink} x="-5%" y="-10%" width="110%" height="120%">
          <feTurbulence type="fractalNoise" baseFrequency={0.12 / edges} numOctaves={2} seed={11} result="warp" />
          <feDisplacementMap in="SourceGraphic" in2="warp" scale={2.5 * edges} xChannelSelector="R" yChannelSelector="G" result="wobbled" />
          {/* Blurred and cut back to a hard edge, so corners round and strokes swell as ink spread into paper does. */}
          <feGaussianBlur in="wobbled" stdDeviation={0.9 * edges} result="spread" />
          <feComponentTransfer in="spread" result="bled">
            <feFuncA type="linear" slope={14} intercept={-5} />
          </feComponentTransfer>
          <feTurbulence type="fractalNoise" baseFrequency={0.75 / holes} numOctaves={2} seed={4} result="noise" />
          {/* Opaque wherever the noise is below the threshold, falling sharply to a pinhole above it. */}
          <feColorMatrix
            in="noise"
            type="matrix"
            values={`0 0 0 0 0  0 0 0 0 0  0 0 0 0 0  -30 0 0 0 ${30 * threshold}`}
            result="holes"
          />
          <feComposite in="bled" in2="holes" operator="in" />
        </filter>
      </svg>
      <span
        ref={stampRef}
        style={{ filter: `url(#${ink})` }}
        className="inline-block -rotate-[6deg] rounded-[0.12em] border-[max(0.08em,1.5px)] border-coral px-[0.3em] pt-[0.06em] pb-[0.12em] font-stamp text-[1.12em] font-black tracking-[0.06em] text-coral uppercase outline-[max(0.03em,1px)] outline-offset-[max(0.07em,1.5px)] outline-coral outline-solid"
      >
        Warrant
      </span>
      {/* Signed beneath the stamp, on a signature line, the way a warrant is issued. */}
      <span className="mr-[0.1em] inline-flex items-end gap-[0.35em]">
        <span aria-hidden className="text-[max(0.3em,10px)] font-bold text-taupe">
          ×
        </span>
        <span className="border-b-[max(0.04em,1px)] border-taupe/70 px-[0.3em] pb-[0.06em] font-signature text-[max(0.56em,16px)] leading-[1.1] font-semibold tracking-normal text-cream">
          for Laravel
        </span>
      </span>
    </span>
  )
}
