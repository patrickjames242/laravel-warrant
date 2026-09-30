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
 * The height on screen, as a fraction of the viewport, the bridge's tip is held
 * at while it follows the scroll. The bridge is long enough to run well off
 * screen, so rather than drawing on a clock it is drawn by scrolling: it is
 * drawn down to wherever it crosses this line, so its tip keeps pace with the
 * page however much of it runs across rather than down.
 */
const TIP_LINE = 0.8
/**
 * How closely the bridge's tip follows the scroll: each second it closes the
 * gap as a lag of this many seconds would, so it glides after the reader rather
 * than jumping with every turn of the wheel. However small the gap, it moves at
 * least at the line's usual speed, so it lands rather than creeping up on its mark.
 */
const FOLLOW_LAG = 0.12
/**
 * How far down, in screen heights, a page may already be scrolled when it
 * opens and still play its opening. A reader who arrives further down, as on a
 * reload partway through, is shown the finished page instead.
 */
const FURTHEST_START = 0.5
/** How long, in milliseconds, the page must stop reflowing before its triggers are measured again. */
const REFLOW_SETTLE = 120
/** How far into its step, in seconds, the bridge begins to follow the scroll. */
const BRIDGE_DELAY = 0.05
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
 * The page opens with the hero's intro rising in, line by line. The line
 * then runs from the rule set, through the question they pick, into the
 * code and its SQL, across into the timeline and down its three steps to the
 * Warrant bar. It is broken into steps, each started by its own part of the
 * page scrolling into view, and the steps play strictly in order: a step that
 * comes into view early waits for the line to reach it, so the line only ever
 * travels forward from where it is. Each thing the line reaches answers as it
 * arrives: panels and text rise in, and the step circles and connector dots
 * grow into place.
 *
 * What is already on screen when the page loads plays at once; everything
 * below waits to be scrolled to. A page that opens already scrolled well down,
 * and readers who ask for reduced motion, get the finished page and none of
 * this.
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
          gsap.to(target, {
            autoAlpha: 1,
            scale: 1,
            duration: 0.5,
            ease: 'power3.out',
            clearProps: SETTLED,
          })

        const rise = (targets: Element | Element[], stagger = 0) =>
          gsap.to(targets, {
            autoAlpha: 1,
            y: 0,
            duration: 0.7,
            ease: 'power3.out',
            stagger,
            clearProps: SETTLED,
          })

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
            {
              clipPath: SHOWN_RAIL,
              duration: drawTime(rail.offsetHeight),
              ease: DRAW_EASE,
              clearProps: 'clipPath',
            },
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
        const intro = gsap.utils.toArray<HTMLElement>('[data-choreo="intro"]', root)
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
        const hidden = gsap.context(() => {
          gsap.set(root.querySelectorAll('[data-flow-reveal]'), {
            strokeDashoffset: REVEAL_LENGTH,
          })
          gsap.set(intro, { autoAlpha: 0, y: 24 })
          gsap.set([rules, code, output, heading], { autoAlpha: 0, y: 28 })
          gsap.set(tabs, { autoAlpha: 0, y: 16 })
          gsap.set(branches, { autoAlpha: 0 })
          gsap.set([...dots, ...pieces.map((piece) => piece.node)], {
            autoAlpha: 0,
            scale: 0.7,
          })
          gsap.set(
            pieces.flatMap((piece) => piece.items),
            { autoAlpha: 0, y: 24 },
          )
          gsap.set([...pieces.map((piece) => piece.rail), railFinal], {
            clipPath: HIDDEN_RAIL,
          })
          gsap.set(bar, {
            autoAlpha: 0,
            scaleX: 0.94,
            transformOrigin: 'left center',
          })
          gsap.set(entries, { autoAlpha: 0, y: 16 })
        })

        /*
         * The bridge follows the reader rather than a clock. `goal` is the furthest
         * fraction of it the reader has scrolled to, and never shrinks, so scrolling
         * back up leaves the line drawn. Once the chain reaches the bridge, `drawn`
         * chases `goal` frame by frame, and the steps after it wait for it to land.
         */
        const bridgeReveal = one(bridge, '[data-flow-reveal]')
        const bridgePath = bridge.querySelector<SVGPathElement>('path.flow-path')
        const bridgeLine = { drawn: 0 }
        let goal = 0
        let bridgeStarted = false
        let bridgeLanded = false
        let headingRisen = false
        let headingAt = 1
        let chasing = false
        let bridgeTrack: ScrollTrigger | undefined

        /**
         * How far along the bridge, as a fraction, it is drawn when it runs down
         * to the tip line. The bridge only ever runs down or across, so the height
         * of a point along it never falls, and the crossing can be searched for.
         */
        const scrolledTo = (): number => {
          const length = bridgePath?.getTotalLength() ?? 0
          const matrix = bridgePath?.getScreenCTM()
          if (!bridgePath || !length || !matrix) return 0
          const line = window.innerHeight * TIP_LINE
          const above = (at: number) => bridgePath.getPointAtLength(at).matrixTransform(matrix).y <= line
          if (above(length)) return 1
          if (!above(0)) return 0
          let low = 0
          let high = length
          while (high - low > 2) {
            const middle = (low + high) / 2
            if (above(middle)) low = middle
            else high = middle
          }
          return low / length
        }

        /** How far along the bridge, as a fraction, its tip comes level with the heading. */
        const headingFraction = (): number => {
          const length = bridgePath?.getTotalLength() ?? 0
          if (!bridgePath || !length) return 0
          const level = layoutBox(heading).top - layoutBox(root).top - 40
          for (let at = 0; at <= length; at += 12) {
            if (bridgePath.getPointAtLength(at).y >= level) return at / length
          }
          return 1
        }

        const showBridge = () => {
          gsap.set(bridgeReveal, {
            strokeDashoffset: REVEAL_LENGTH * (1 - bridgeLine.drawn),
          })
          if (!headingRisen && bridgeLine.drawn >= headingAt) {
            headingRisen = true
            rise(heading)
          }
        }

        /** One frame of the tip's chase after `goal`, of `delta` milliseconds. */
        const step = (_time: number, delta: number) => {
          const length = lineLength(bridge)
          const gap = (goal - bridgeLine.drawn) * length
          const pixels = Math.max(gap / FOLLOW_LAG, LINE_SPEED) * (delta / 1000)
          bridgeLine.drawn = length && pixels < gap ? bridgeLine.drawn + pixels / length : goal
          showBridge()
          if (bridgeLine.drawn < goal) return
          gsap.ticker.remove(step)
          chasing = false
          if (goal < 1) return
          bridgeLanded = true
          bridgeTrack?.kill()
          gsap.set(bridgeReveal, { clearProps: 'strokeDashoffset' })
          reach(Math.max(wanted, bridgeAt + 1))
        }

        const chase = () => {
          if (!bridgeStarted || bridgeLanded || chasing || goal <= bridgeLine.drawn) return
          chasing = true
          gsap.ticker.add(step)
        }

        const startBridge = () => {
          bridgeStarted = true
          headingAt = headingFraction()
          goal = Math.max(goal, scrolledTo())
          chase()
        }

        /** A connector drawn down into a panel, whose dot and panel answer as it lands. */
        const intoPanel = (connector: HTMLElement, panel: HTMLElement): Built => {
          const timeline = gsap.timeline().add(draw(connector))
          const lands = timeline.duration()
          const dot = connector.querySelector('[data-choreo="dot"]')
          if (dot) timeline.add(arrive(dot), lands)
          timeline.add(rise(panel), lands - 0.1)
          return { timeline, handoff: lands + 0.15 }
        }

        const bridgeStep: Step = {
          // Once the hero's lines have landed, the bridge hands the pace to the
          // reader, and the heading rises as the line comes level with it. The
          // bridge starts a moment into its step rather than at its very start:
          // a step joining a chain that has already finished is placed at the
          // playhead, and only a callback past the playhead is played over.
          trigger: output,
          start: 'bottom 88%',
          build: () => ({
            timeline: gsap.timeline().call(startBridge, undefined, BRIDGE_DELAY),
            handoff: 0,
          }),
        }

        const steps: Step[] = [
          {
            trigger: one(root, '[data-choreo="intro"]'),
            build: () => ({
              timeline: gsap.timeline().add(rise(intro, 0.09)),
              handoff: 0.6,
            }),
          },
          {
            trigger: rules,
            build: () => ({
              timeline: gsap.timeline().add(rise(rules)),
              handoff: 0.35,
            }),
          },
          {
            trigger: fan,
            build: () => {
              const timeline = gsap.timeline().add(draw(fan))
              const lands = timeline.duration()
              // The grey branches to the unchosen questions follow the tabs they lead to.
              timeline.add(rise(tabs, 0.06), lands - 0.15).to(
                branches,
                {
                  autoAlpha: 1,
                  duration: 0.5,
                  ease: 'power2.out',
                  clearProps: SETTLED,
                },
                '>-0.15',
              )
              return { timeline, handoff: lands + 0.1 }
            },
          },
          { trigger: toCode, build: () => intoPanel(toCode, code) },
          { trigger: toOutput, build: () => intoPanel(toOutput, output) },
          bridgeStep,
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
                return {
                  timeline: gsap.timeline().add(unrolling),
                  handoff: unrolling.duration(),
                }
              },
            },
          ]),
          {
            trigger: railFinal,
            build: () => {
              const timeline = gsap.timeline().add(unroll(railFinal))
              const lands = timeline.duration()
              timeline
                .to(
                  bar,
                  {
                    autoAlpha: 1,
                    scaleX: 1,
                    duration: 0.7,
                    ease: 'power3.out',
                    clearProps: SETTLED,
                  },
                  lands,
                )
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
        // The steps past the bridge join only once it has landed on the first circle.
        const bridgeAt = steps.indexOf(bridgeStep)
        let wanted = 0
        let joined = 0
        let nextStart = 0
        function reach(index: number) {
          wanted = Math.max(wanted, index)
          for (; joined <= index; joined++) {
            if (joined > bridgeAt && !bridgeLanded) break
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

        let pending = 0
        let reflowed: ResizeObserver | undefined
        // Made a frame after the page mounts, outside what `media` reverts on its own.
        const triggers: ScrollTrigger[] = []

        const begin = () => {
          // The router restores the scroll position after the page mounts, so
          // where the reader opens the page is only known a frame later.
          if (window.scrollY > window.innerHeight * FURTHEST_START) {
            hidden.revert()
            return
          }

          const follow = () => {
            goal = Math.max(goal, scrolledTo())
            chase()
          }
          bridgeTrack = ScrollTrigger.create({
            trigger: bridge,
            start: 'top bottom',
            end: 'bottom top',
            onUpdate: follow,
            onRefresh: follow,
          })

          triggers.push(bridgeTrack)
          steps.forEach((step, index) => {
            triggers.push(
              ScrollTrigger.create({
                trigger: step.trigger,
                start: step.start ?? START,
                once: true,
                onEnter: () => {
                  reach(index)
                },
              }),
            )
          })

          // Where each step starts moves when the page reflows: fonts loading, a
          // question changing the panel's height, the timeline placing its nodes.
          // A reflow that is animated, like a panel easing to its new height, is
          // measured once it settles rather than on every frame of it.
          reflowed = new ResizeObserver(() => {
            clearTimeout(pending)
            pending = window.setTimeout(() => {
              ScrollTrigger.refresh()
            }, REFLOW_SETTLE)
          })
          reflowed.observe(root)
        }
        const opening = requestAnimationFrame(begin)

        return () => {
          cancelAnimationFrame(opening)
          reflowed?.disconnect()
          clearTimeout(pending)
          gsap.ticker.remove(step)
          triggers.forEach((trigger) => {
            trigger.kill()
          })
        }
      })

      return () => {
        media.revert()
      }
    },
    { scope },
  )
}
