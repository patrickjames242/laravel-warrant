import { useGSAP } from '@gsap/react'
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
import type { RefObject } from 'react'
import { layoutBox } from './flow'
import { REVEAL_LENGTH } from './FlowSegment'

gsap.registerPlugin(useGSAP, ScrollTrigger)

/**
 * How fast the tip of the line travels, in pixels a second, on every stretch
 * alike, and the least time any stretch takes, so even the shortest visibly
 * travels rather than blinking into place.
 */
const LINE_SPEED = 750
const SHORTEST_DRAW = 0.3
/**
 * The curve each stretch is drawn on: quick to leave, then easing off, so the
 * line settles into whatever it reaches rather than striking it at full speed.
 */
const DRAW_EASE = 'power2.out'
/** A step begins once its trigger's top passes this far down the viewport. */
const START = 'top 88%'
/**
 * How many steps may wait their turn before the chain hurries. A reader who
 * scrolls well ahead of the line is caught up with rather than kept waiting,
 * each further waiting step adding `HURRY_PER_STEP` to the pace, up to
 * `MOST_HURRY` times the usual.
 */
const PATIENCE = 2
const HURRY_PER_STEP = 0.35
const MOST_HURRY = 2

const HIDDEN_RAIL = 'inset(0% 0% 100% 0%)'
const SHOWN_RAIL = 'inset(0% 0% 0% 0%)'
const SETTLED = 'transform,opacity,visibility'

/** One link in the chain: what starts it, and what it plays once its turn comes. */
interface Step {
  trigger: Element
  start?: string
  build: () => Built
}

/** A step's animation, and how far into it the next step may begin. */
interface Built {
  timeline: gsap.core.Timeline
  handoff: number
}

function drawTime(pixels: number): number {
  return Math.max(SHORTEST_DRAW, pixels / LINE_SPEED)
}

/**
 * How far into a stretch's drawing, in seconds, its tip reaches `fraction` of
 * the way along. The stretch is drawn on `DRAW_EASE`, so this is found on that
 * curve rather than by dividing, which would only hold at a constant speed.
 */
function timeToReach(fraction: number, duration: number): number {
  const eased = gsap.parseEase(DRAW_EASE)
  let low = 0
  let high = 1
  for (let step = 0; step < 20; step++) {
    const middle = (low + high) / 2
    if (eased(middle) < fraction) low = middle
    else high = middle
  }
  return high * duration
}

/** The drawn length of the dotted path inside a segment, in pixels. */
function lineLength(segment: Element): number {
  return segment.querySelector<SVGPathElement>('path.flow-path')?.getTotalLength() ?? 0
}

function one(scope: Element, selector: string): HTMLElement {
  const found = scope.querySelector<HTMLElement>(selector)
  if (!found) throw new Error(`The home page has nothing matching ${selector} to animate.`)
  return found
}

/**
 * The home page's opening sequence, as one continuous line that the reader
 * scrolls along.
 *
 * The line runs from the rule set, through the question they pick, into the
 * code and its SQL, across into the timeline and down its three steps to the
 * Warrant bar. It is broken into steps, each started by its own part of the
 * page scrolling into view, and the steps play strictly in order: a step that
 * comes into view early waits for the line to reach it, so the line only ever
 * travels forward from where it is. Each thing the line reaches answers as it
 * arrives: panels and text rise in, and the step circles and connector dots
 * grow into place.
 *
 * What is already on screen when the page loads plays at once; everything
 * below waits to be scrolled to. Readers who ask for reduced motion get the
 * finished page and none of this.
 *
 * `scope` must hold the hero and the timeline, whose parts carry the
 * `data-choreo` names this looks for.
 */
export function useFlowChoreography(scope: RefObject<HTMLElement | null>) {
  useGSAP(
    () => {
      const root = scope.current
      if (!root) return

      const media = gsap.matchMedia()
      media.add('(prefers-reduced-motion: no-preference)', () => {
        /** A point on the line — a step circle or a connector dot — growing into place as the line arrives. */
        const arrive = (target: Element) =>
          gsap.to(target, { autoAlpha: 1, scale: 1, duration: 0.5, ease: 'power3.out', clearProps: SETTLED })

        const rise = (targets: Element | Element[], stagger = 0) =>
          gsap.to(targets, { autoAlpha: 1, y: 0, duration: 0.7, ease: 'power3.out', stagger, clearProps: SETTLED })

        const draw = (segment: Element) =>
          gsap.fromTo(
            segment.querySelector('[data-flow-reveal]'),
            { strokeDashoffset: REVEAL_LENGTH },
            {
              strokeDashoffset: 0,
              duration: drawTime(lineLength(segment)),
              ease: DRAW_EASE,
              autoRound: false,
              clearProps: 'strokeDashoffset',
            },
          )

        const unroll = (rail: HTMLElement) =>
          gsap.fromTo(
            rail,
            { clipPath: HIDDEN_RAIL },
            { clipPath: SHOWN_RAIL, duration: drawTime(rail.offsetHeight), ease: DRAW_EASE, clearProps: 'clipPath' },
          )

        const rules = one(root, '[data-choreo="rules"]')
        const fan = one(root, '[data-choreo="fan"]')
        const toCode = one(root, '[data-choreo="to-code"]')
        const code = one(root, '[data-choreo="code"]')
        const toOutput = one(root, '[data-choreo="to-output"]')
        const output = one(root, '[data-choreo="output"]')
        const bridge = one(root, '[data-choreo="bridge"]')
        const heading = one(root, '[data-choreo="heading"]')
        const railFinal = one(root, '[data-choreo="rail-final"]')
        const bar = one(root, '[data-choreo="bar"]')
        const tabs = gsap.utils.toArray<HTMLElement>('[data-choreo="tab"]', root)
        const branches = gsap.utils.toArray<SVGPathElement>('[data-choreo="branch"]', root)
        const dots = gsap.utils.toArray<HTMLElement>('[data-choreo="dot"]', root)
        const entries = gsap.utils.toArray<HTMLElement>('[data-choreo="entries"]', root)
        const pieces = gsap.utils.toArray<HTMLElement>('[data-choreo="piece"]', root).map((piece) => ({
          node: one(piece, '[data-node]'),
          rail: one(piece, '[data-line]'),
          items: gsap.utils.toArray<HTMLElement>('[data-choreo="item"]', piece),
        }))

        // Everything starts hidden, set before the first paint so nothing flashes.
        gsap.set(root.querySelectorAll('[data-flow-reveal]'), { strokeDashoffset: REVEAL_LENGTH })
        gsap.set([rules, code, output, heading], { autoAlpha: 0, y: 28 })
        gsap.set(tabs, { autoAlpha: 0, y: 16 })
        gsap.set(branches, { autoAlpha: 0 })
        gsap.set([...dots, ...pieces.map((piece) => piece.node)], { autoAlpha: 0, scale: 0.7 })
        gsap.set(
          pieces.flatMap((piece) => piece.items),
          { autoAlpha: 0, y: 24 },
        )
        gsap.set([...pieces.map((piece) => piece.rail), railFinal], { clipPath: HIDDEN_RAIL })
        gsap.set(bar, { autoAlpha: 0, scaleX: 0.94, transformOrigin: 'left center' })
        gsap.set(entries, { autoAlpha: 0, y: 16 })

        /** A connector drawn down into a panel, whose dot and panel answer as it lands. */
        const intoPanel = (connector: HTMLElement, panel: HTMLElement): Built => {
          const timeline = gsap.timeline().add(draw(connector))
          const lands = timeline.duration()
          const dot = connector.querySelector('[data-choreo="dot"]')
          if (dot) timeline.add(arrive(dot), lands)
          timeline.add(rise(panel), lands - 0.1)
          return { timeline, handoff: lands + 0.15 }
        }

        const steps: Step[] = [
          {
            trigger: rules,
            build: () => ({ timeline: gsap.timeline().add(rise(rules)), handoff: 0.35 }),
          },
          {
            trigger: fan,
            build: () => {
              const timeline = gsap.timeline().add(draw(fan))
              const lands = timeline.duration()
              // The grey branches to the unchosen questions follow the tabs they lead to.
              timeline
                .add(rise(tabs, 0.06), lands - 0.15)
                .to(branches, { autoAlpha: 1, duration: 0.5, ease: 'power2.out', clearProps: SETTLED }, '>-0.15')
              return { timeline, handoff: lands + 0.1 }
            },
          },
          { trigger: toCode, build: () => intoPanel(toCode, code) },
          { trigger: toOutput, build: () => intoPanel(toOutput, output) },
          {
            trigger: output,
            start: 'bottom 88%',
            build: () => {
              const drawing = draw(bridge)
              const timeline = gsap.timeline().add(drawing)
              // The heading rises as the line comes level with it on its way down.
              const path = bridge.querySelector<SVGPathElement>('path.flow-path')
              const level = layoutBox(heading).top - layoutBox(root).top - 40
              const length = path?.getTotalLength() ?? 0
              let reached = length
              for (let at = 0; path && at <= length; at += 12) {
                if (path.getPointAtLength(at).y >= level) {
                  reached = at
                  break
                }
              }
              timeline.add(rise(heading), timeToReach(length ? reached / length : 1, drawing.duration()))
              return { timeline, handoff: drawing.duration() }
            },
          },
          ...pieces.flatMap((piece): Step[] => [
            {
              trigger: piece.node,
              build: () => {
                const timeline = gsap.timeline().add(arrive(piece.node)).add(rise(piece.items, 0.08), 0.1)
                return { timeline, handoff: 0.3 }
              },
            },
            {
              trigger: piece.rail,
              build: () => {
                const unrolling = unroll(piece.rail)
                return { timeline: gsap.timeline().add(unrolling), handoff: unrolling.duration() }
              },
            },
          ]),
          {
            trigger: railFinal,
            build: () => {
              const timeline = gsap.timeline().add(unroll(railFinal))
              const lands = timeline.duration()
              timeline
                .to(bar, { autoAlpha: 1, scaleX: 1, duration: 0.7, ease: 'power3.out', clearProps: SETTLED }, lands)
                .add(rise(entries, 0.1), lands + 0.25)
              return { timeline, handoff: lands }
            },
          },
        ]

        // The chain: steps join it strictly in order, each where the last hands off.
        const chain = gsap.timeline()
        const startsAt: number[] = []
        chain.eventCallback('onUpdate', () => {
          const waiting = startsAt.filter((at) => at > chain.time()).length
          chain.timeScale(gsap.utils.clamp(1, MOST_HURRY, 1 + (waiting - PATIENCE) * HURRY_PER_STEP))
        })
        let joined = 0
        let nextStart = 0
        const reach = (index: number) => {
          for (; joined <= index; joined++) {
            const step = steps[joined]
            if (!step) break
            const { timeline, handoff } = step.build()
            const at = Math.max(nextStart, chain.time())
            chain.add(timeline, at)
            startsAt.push(at)
            nextStart = at + handoff
          }
          chain.play()
        }

        steps.forEach((step, index) => {
          ScrollTrigger.create({
            trigger: step.trigger,
            start: step.start ?? START,
            once: true,
            onEnter: () => {
              reach(index)
            },
          })
        })

        // Where each step starts moves when the page reflows: fonts loading, a
        // question changing the panel's height, the timeline placing its nodes.
        let pending = 0
        const reflowed = new ResizeObserver(() => {
          cancelAnimationFrame(pending)
          pending = requestAnimationFrame(() => {
            ScrollTrigger.refresh()
          })
        })
        reflowed.observe(root)

        return () => {
          reflowed.disconnect()
          cancelAnimationFrame(pending)
        }
      })

      return () => {
        media.revert()
      }
    },
    { scope },
  )
}
