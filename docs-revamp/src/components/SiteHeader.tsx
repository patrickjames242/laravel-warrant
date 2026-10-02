import { Link } from '@tanstack/react-router'
import { links } from '../lib/links'
import { Logo } from './Logo'
import { SiteLink } from './SiteLink'
import { ThemeMenu } from './ThemeMenu'

const NAV = [
  { label: 'Why Warrant', href: links.whyWarrant },
  { label: 'Docs', href: links.installation },
  { label: 'Guides', href: links.guides },
  { label: 'API', href: links.cheatSheet },
] as const

export type NavLabel = (typeof NAV)[number]['label']

interface SiteHeaderProps {
  /**
   * Draws the header docs pages use: as wide as the docs layout, marked as the
   * docs, and with `active` shown as the current nav link.
   */
  docs?: { active: NavLabel }
}

export function SiteHeader({ docs }: SiteHeaderProps) {
  const frame = docs ? 'max-w-[1584px] px-[clamp(17.5px,3.5vw,35px)]' : 'max-w-330 px-[clamp(22px,4.4vw,53px)]'

  return (
    <header data-sticky-bar className="sticky top-0 z-50 border-b border-line-1 bg-ink/84 backdrop-blur-md">
      <nav aria-label="Primary" className={`mx-auto flex h-16 items-center gap-4 ${frame}`}>
        <Link to="/" className="flex flex-none items-center gap-2.5 text-cream sm:mr-4">
          <Logo />
          {docs && (
            <span className="rounded-[4.5px] border border-line-3 px-1.5 py-1 font-mono text-[11.5px] leading-none font-medium tracking-[.08em] text-taupe">
              DOCS
            </span>
          )}
        </Link>

        {/*
          Links that do not fit on one row wrap out of sight rather than onto a
          second one. At phone width there is no room for even the first.
        */}
        <div className="hidden h-5 min-w-0 flex-1 flex-wrap gap-x-6.5 gap-y-1 overflow-hidden text-sm leading-5 font-medium sm:mr-4 sm:flex">
          {NAV.map((item) => (
            <SiteLink
              key={item.label}
              href={item.href}
              className={item.label === docs?.active ? 'text-cream' : 'text-tan'}
            >
              {item.label}
            </SiteLink>
          ))}
        </div>

        <div className="ml-auto flex flex-none items-center gap-2.5">
          <ThemeMenu />
          <a
            href={links.github}
            aria-label="GitHub"
            className="flex h-9 min-w-9 items-center justify-center gap-2 rounded-md border border-line-3 bg-surface font-mono light:bg-surface/45 text-[14.5px] leading-none font-medium text-sand hover:border-line-5 hover:text-cream sm:px-3"
          >
            <GitHubIcon />
            <span className="hidden sm:inline">GitHub</span>
          </a>
          {!docs && (
            <SiteLink
              href={links.installation}
              className="flex h-9 items-center rounded-md bg-cream px-3.5 text-sm leading-none font-semibold text-ink hover:text-ink"
            >
              Get started
            </SiteLink>
          )}
        </div>
      </nav>
    </header>
  )
}

/** GitHub's mark, in the site's coral. */
function GitHubIcon() {
  return (
    <svg aria-hidden width="16" height="16" viewBox="0 0 16 16" fill="currentColor" className="flex-none text-coral">
      <path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0 0 16 8c0-4.42-3.58-8-8-8z" />
    </svg>
  )
}
