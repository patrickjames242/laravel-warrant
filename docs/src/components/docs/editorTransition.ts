/**
 * The motion of the page editor opening and closing. The layout switches in
 * one step; these animations then play the difference, so the article appears
 * to slide aside for the editor, and back when it goes.
 *
 * Elements that the switch removes are copied just before it and left in
 * place as still images, so they can leave on screen instead of vanishing.
 */

const DURATION = 450
/** The site's own `--ease-glide`: a quick start that settles slowly. */
const EASING = 'cubic-bezier(0.32, 0.72, 0, 1)'
const SIDE_BY_SIDE = '(min-width: 990px)'

const SELECTORS = {
  article: 'main article',
  sidebar: '[data-docs-sidebar]',
  contents: '[data-docs-contents]',
  editor: '[data-editor-pane]',
} as const

type Part = keyof typeof SELECTORS

/** Where things stood just before the layout switched. */
export interface LayoutSnapshot {
  opening: boolean
  articleLeft: number | undefined
  /** Still copies of what the switch is about to remove, keyed by what they were. */
  ghosts: Partial<Record<Part, HTMLElement>>
}

function find(part: Part): HTMLElement | null {
  return document.querySelector<HTMLElement>(SELECTORS[part])
}

function isShown(element: HTMLElement): boolean {
  const box = element.getBoundingClientRect()
  return box.width > 0 && box.height > 0 && getComputedStyle(element).visibility !== 'hidden'
}

/**
 * A copy of the element fixed over the spot it fills, scrolled as it is, that
 * does nothing but look like it. The copy's ids are dropped so the page never
 * holds two of one.
 */
function ghostOf(element: HTMLElement): HTMLElement {
  const box = element.getBoundingClientRect()
  const ghost = element.cloneNode(true) as HTMLElement
  for (const withId of ghost.querySelectorAll('[id]')) withId.removeAttribute('id')
  ghost.removeAttribute('id')
  ghost.setAttribute('aria-hidden', 'true')
  ghost.inert = true
  Object.assign(ghost.style, {
    position: 'fixed',
    top: `${box.top}px`,
    left: `${box.left}px`,
    width: `${box.width}px`,
    height: `${box.height}px`,
    margin: '0',
    zIndex: '44',
    pointerEvents: 'none',
  })
  document.body.append(ghost)

  // A clone starts scrolled to its top; set every scrolled part back to where the original was.
  const scrolled = [element, ...element.querySelectorAll<HTMLElement>('*')].filter((each) => each.scrollTop > 0)
  for (const original of scrolled) {
    const path = pathTo(element, original)
    const copy = path.reduce<Element | undefined>((node, index) => node?.children[index], ghost)
    if (copy) copy.scrollTop = original.scrollTop
  }
  return ghost
}

/** The child indexes leading from `root` down to `node`. */
function pathTo(root: Element, node: Element): number[] {
  const path: number[] = []
  let current = node
  while (current !== root) {
    const parent = current.parentElement
    if (!parent) break
    path.unshift([...parent.children].indexOf(current))
    current = parent
  }
  return path
}

/**
 * Records where the article is and copies what the switch is about to remove:
 * opening, the sidebar and the page's list of headings, where the editor will
 * take their room; closing, the editor itself.
 */
export function captureLayout(opening: boolean): LayoutSnapshot {
  const article = find('article')
  const ghosts: LayoutSnapshot['ghosts'] = {}
  for (const part of opening ? (['sidebar', 'contents'] as const) : (['editor'] as const)) {
    const element = find(part)
    if (element && isShown(element)) ghosts[part] = ghostOf(element)
  }
  return { opening, articleLeft: article?.getBoundingClientRect().left, ghosts }
}

/**
 * Animates an element, first stopping any motion it is still in. A toggle
 * made mid-animation measures where the element is on screen, so the new
 * motion starts from there and the old one has nothing left to add.
 */
function play(element: Element, keyframes: Keyframe[], delay = 0): Animation {
  for (const running of element.getAnimations()) running.cancel()
  return element.animate(keyframes, { duration: DURATION, easing: EASING, delay, fill: 'backwards' })
}

/**
 * Removes a copy once its animation ends. A page in a background tab can hold
 * its animations still, so the copy goes after twice the duration regardless,
 * rather than being left over the page.
 */
function dropWhenDone(ghost: HTMLElement, animation: Animation): void {
  const remove = () => {
    ghost.remove()
  }
  animation.finished.then(remove, remove)
  window.setTimeout(remove, DURATION * 2)
}

/**
 * Plays the change from the snapshot to the layout now on screen. The article
 * slides from where it was to where it is. Beside the article the editor comes
 * in from the right and leaves the same way; below 990px it rises from the
 * bottom and sinks back. With reduced motion asked for, the layout just
 * switches.
 */
export function playTransition(snapshot: LayoutSnapshot): void {
  const { opening, ghosts } = snapshot

  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    for (const ghost of Object.values(ghosts)) ghost.remove()
    return
  }

  const sideBySide = window.matchMedia(SIDE_BY_SIDE).matches
  const offscreen = sideBySide ? 'translateX(100%)' : 'translateY(100%)'

  const article = find('article')
  if (article && snapshot.articleLeft !== undefined) {
    const shift = snapshot.articleLeft - article.getBoundingClientRect().left
    if (Math.abs(shift) > 1) play(article, [{ transform: `translateX(${shift}px)` }, { transform: 'none' }])
  }

  if (opening) {
    const editor = find('editor')
    if (editor) play(editor, [{ transform: offscreen }, { transform: 'none' }])
    if (ghosts.sidebar) {
      dropWhenDone(ghosts.sidebar, play(ghosts.sidebar, [{ transform: 'none' }, { transform: 'translateX(-100%)', opacity: 0 }]))
    }
    if (ghosts.contents) {
      dropWhenDone(ghosts.contents, ghosts.contents.animate([{ opacity: 1 }, { opacity: 0 }], { duration: DURATION / 2, easing: EASING, fill: 'forwards' }))
    }
    return
  }

  if (ghosts.editor) dropWhenDone(ghosts.editor, play(ghosts.editor, [{ transform: 'none' }, { transform: offscreen }]))
  const sidebar = find('sidebar')
  if (sidebar && isShown(sidebar)) play(sidebar, [{ transform: 'translateX(-100%)', opacity: 0 }, { transform: 'none', opacity: 1 }])
  const contents = find('contents')
  if (contents && isShown(contents)) play(contents, [{ opacity: 0 }, { opacity: 1 }], DURATION / 3)
}
