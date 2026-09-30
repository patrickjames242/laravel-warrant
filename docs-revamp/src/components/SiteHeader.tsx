import { Link } from '@tanstack/react-router'
import { links } from '../lib/links'
import { Logo } from './Logo'

const NAV = [
  { label: 'Why Warrant', href: links.whyWarrant },
  { label: 'Docs', href: links.installation },
  { label: 'Guides', href: links.guides },
  { label: 'API', href: links.cheatSheet },
]

export function SiteHeader() {
  return (
    <header className="sticky top-0 z-50 border-b border-line-1 bg-ink/84 backdrop-blur-md">
      <nav aria-label="Primary" className="mx-auto flex h-16 max-w-330 items-center gap-4 px-[clamp(22px,4.4vw,53px)]">
        <Link to="/" className="flex-none text-cream sm:mr-4">
          <Logo />
        </Link>

        {/*
          Links that do not fit on one row wrap out of sight rather than onto a
          second one. At phone width there is no room for even the first.
        */}
        <div className="hidden h-5 min-w-0 flex-1 flex-wrap gap-x-6.5 gap-y-1 overflow-hidden sm:mr-4 sm:flex text-sm leading-5 font-medium">
          {NAV.map((item) => (
            <a key={item.label} href={item.href} className="text-tan">
              {item.label}
            </a>
          ))}
        </div>

        <div className="ml-auto flex flex-none items-center gap-2.5">
          <a
            href={links.github}
            aria-label="GitHub"
            className="flex h-9 items-center gap-2 rounded-md border border-line-3 px-3 font-mono text-[14.5px] leading-none font-medium text-sand"
          >
            <span className="text-coral">★</span>
            <span className="hidden sm:inline">GitHub</span>
          </a>
          <a
            href={links.installation}
            className="flex h-9 items-center rounded-md bg-cream px-3.5 text-sm leading-none font-semibold text-ink hover:text-ink"
          >
            Get started
          </a>
        </div>
      </nav>
    </header>
  )
}
