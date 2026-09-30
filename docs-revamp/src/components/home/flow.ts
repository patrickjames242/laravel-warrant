/**
 * The coral dotted paths that carry the reader from the rule set, through the
 * question they pick, down into the three pieces.
 */

/**
 * The largest radius a path's corners are drawn with unless it asks for another.
 * A corner is also kept within half the distance the path crosses, and within
 * the run it turns out of and into.
 */
export const CORNER_RADIUS = 28

export interface Point {
  x: number
  y: number
}

export interface Box {
  left: number
  top: number
  width: number
  height: number
}

/**
 * Where an element is laid out on the page, ignoring any transform on it or its
 * ancestors. The choreography slides and scales things as they appear, and a
 * path measured mid-animation would point at where they were passing through
 * rather than where they come to rest.
 */
export function layoutBox(element: HTMLElement): Box {
  let left = 0
  let top = 0
  for (let at: HTMLElement | null = element; at; at = at.offsetParent as HTMLElement | null) {
    left += at.offsetLeft
    top += at.offsetTop
  }
  return { left, top, width: element.offsetWidth, height: element.offsetHeight }
}

/**
 * A path that runs down from `from` to the height `turn`, across to above `to`,
 * and down again to `to`, rounding both corners to at most `maxRadius`. With
 * nothing to cross it is a straight drop.
 */
export function bend(from: Point, to: Point, turn: number, maxRadius = CORNER_RADIUS): string {
  if (Math.abs(from.x - to.x) < 0.5) return `M${from.x} ${from.y}V${to.y}`

  const direction = Math.sign(to.x - from.x)
  const radius = Math.max(0, Math.min(maxRadius, Math.abs(to.x - from.x) / 2, turn - from.y, to.y - turn))

  return [
    `M${from.x} ${from.y}`,
    `V${turn - radius}`,
    `Q${from.x} ${turn} ${from.x + direction * radius} ${turn}`,
    `H${to.x - direction * radius}`,
    `Q${to.x} ${turn} ${to.x} ${turn + radius}`,
    `V${to.y}`,
  ].join('')
}
