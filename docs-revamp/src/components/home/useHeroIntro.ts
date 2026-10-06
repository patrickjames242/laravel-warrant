import { useGSAP } from '@gsap/react'
import gsap from 'gsap'
import type { RefObject } from 'react'

gsap.registerPlugin(useGSAP)

/**
 * How far down, in screen heights, a page may already be scrolled when it
 * opens and still play its intro. A reader who arrives further down, as on a
 * reload partway through, sees the intro in place.
 */
const FURTHEST_START = 0.5

/**
 * The home page's opening: the hero's intro rises in, line by line, as the
 * page loads. Everything below it is shown as it is; only the question buttons
 * animate it. Readers who ask for reduced motion see the intro in place.
 *
 * `scope` must hold the hero, whose intro lines carry `data-choreo="intro"`.
 */
export function useHeroIntro(scope: RefObject<HTMLElement | null>) {
  useGSAP(
    () => {
      const root = scope.current
      if (!root) return

      const media = gsap.matchMedia()
      media.add('(prefers-reduced-motion: no-preference)', () => {
        const intro = gsap.utils.toArray<HTMLElement>('[data-choreo="intro"]', root)

        // Hidden before the first paint, so nothing flashes.
        const hidden = gsap.context(() => {
          gsap.set(intro, { autoAlpha: 0, y: 24 })
        })

        const begin = () => {
          // The router restores the scroll position after the page mounts, so
          // where the reader opens the page is only known a frame later.
          if (window.scrollY > window.innerHeight * FURTHEST_START) {
            hidden.revert()
            return
          }
          gsap.to(intro, {
            autoAlpha: 1,
            y: 0,
            duration: 0.7,
            ease: 'power3.out',
            stagger: 0.09,
            clearProps: 'transform,opacity,visibility',
          })
        }
        const opening = requestAnimationFrame(begin)

        return () => {
          cancelAnimationFrame(opening)
        }
      })

      return () => {
        media.revert()
      }
    },
    { scope },
  )
}
