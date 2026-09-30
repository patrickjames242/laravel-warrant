export const SECTION_INNER = 'mx-auto max-w-330 px-[clamp(22px,4.4vw,53px)] py-[clamp(97px,12.1vw,167px)]'

/**
 * A theme colour at `percent` of its strength, for the gradients the home page
 * draws in inline styles, where Tailwind's `/opacity` modifier cannot reach.
 */
export function tint(color: string, percent: number): string {
  return `color-mix(in srgb, var(--color-${color}) ${percent}%, transparent)`
}

/** A theme colour, for the same gradients. */
export function themed(color: string): string {
  return `var(--color-${color})`
}

/** The warm glow every section after the hero carries in its top-left corner. */
export const CORNER_GLOW = `radial-gradient(ellipse 42% 38% at 0% 0%, ${tint('glow', 13)}, transparent 70%)`
