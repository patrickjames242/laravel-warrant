import { Link } from '@tanstack/react-router'
import type { ReactNode } from 'react'

interface DocLinkProps {
  slug: string
  /** A heading on the page to land on, without the `#`. */
  hash?: string
  className?: string
  onClick?: () => void
  /** Marks the link as the page being shown. */
  current?: boolean
  children: ReactNode
}

/** A link to a docs page, navigated in place. */
export function DocLink({ slug, hash, className, onClick, current, children }: DocLinkProps) {
  return (
    <Link
      to="/$/"
      params={{ _splat: slug }}
      hash={hash}
      className={className}
      onClick={onClick}
      aria-current={current ? 'page' : undefined}
    >
      {children}
    </Link>
  )
}
