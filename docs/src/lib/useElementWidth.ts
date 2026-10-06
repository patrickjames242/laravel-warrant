import { useLayoutEffect, useRef, useState } from 'react'

/** The rendered width of an element, kept current as it resizes. */
export function useElementWidth<T extends HTMLElement>(fallback: number) {
  const ref = useRef<T>(null)
  const [width, setWidth] = useState(fallback)

  useLayoutEffect(() => {
    const element = ref.current
    if (!element) return

    const measure = () => {
      if (element.clientWidth) setWidth(element.clientWidth)
    }
    measure()

    const observer = new ResizeObserver(measure)
    observer.observe(element)
    return () => {
      observer.disconnect()
    }
  }, [])

  return [ref, width] as const
}
