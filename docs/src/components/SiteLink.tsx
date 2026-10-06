import type { ReactNode } from 'react'
import { findPage } from '../docs/nav'
import { DocLink } from './docs/DocLink'

interface SiteLinkProps {
  href: string
  className?: string
  children: ReactNode
}

/**
 * A link that the router navigates in place when it points at a docs page,
 * landing on the heading its fragment names. Any other href, such as another
 * site or a path the docs do not have, is an ordinary link.
 */
export function SiteLink({ href, className, children }: SiteLinkProps) {
  const match = /^\/([^#?]*?)\/?(?:#(.*))?$/.exec(href)
  const slug = match?.[1]

  if (slug !== undefined && findPage(slug)) {
    return (
      <DocLink slug={slug} hash={match?.[2]} className={className}>
        {children}
      </DocLink>
    )
  }

  return (
    <a href={href} className={className}>
      {children}
    </a>
  )
}
