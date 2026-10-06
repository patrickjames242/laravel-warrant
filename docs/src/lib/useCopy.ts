import { useEffect, useRef, useState } from 'react'

/**
 * Copies text to the clipboard and reports `copied` as true for a moment
 * afterwards, so a button can say so.
 */
export function useCopy(text: string, duration = 1600): { copied: boolean; copy: () => void } {
  const [copied, setCopied] = useState(false)
  const timer = useRef<number>(undefined)

  useEffect(() => () => {
    window.clearTimeout(timer.current)
  }, [])

  const copy = () => {
    void navigator.clipboard.writeText(text).catch(() => undefined)
    setCopied(true)
    window.clearTimeout(timer.current)
    timer.current = window.setTimeout(() => {
      setCopied(false)
    }, duration)
  }

  return { copied, copy }
}
