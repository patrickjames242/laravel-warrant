import type { ReactNode } from 'react'
import { SiteLink } from '../SiteLink'

export function Eyebrow({ children }: { children: ReactNode }) {
  return (
    <div className="font-mono text-xs leading-none font-medium tracking-[.14em] text-taupe uppercase">{children}</div>
  )
}

export function SectionLink({ href, children }: { href: string; children: ReactNode }) {
  return (
    <SiteLink href={href} className="text-[16.5px] leading-none font-medium text-coral-soft">
      {children}
    </SiteLink>
  )
}
