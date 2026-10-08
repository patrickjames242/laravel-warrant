import { Link, useRouterState } from '@tanstack/react-router'
import { links } from '../lib/links'
import { Search } from './Search'
import { SiteLink } from './SiteLink'
import { ThemeMenu } from './ThemeMenu'
import { WarrantForLaravel } from './WarrantForLaravel'

const NAV = [
  { label: 'Why Warrant', href: links.whyWarrant },
  { label: 'Docs', href: links.installation },
  { label: 'Guides', href: links.guides },
  { label: 'API', href: links.cheatSheet },
] as const

export type NavLabel = (typeof NAV)[number]['label']

interface SiteHeaderProps {
  /**
   * Draws the header docs pages use: as wide as the docs layout, and with
   * `active` shown as the current nav link.
   */
  docs?: { active: NavLabel }
}

export function SiteHeader({ docs }: SiteHeaderProps) {
  const frame = docs ? 'max-w-[1584px] px-[clamp(17.5px,3.5vw,35px)]' : 'max-w-330 px-[clamp(22px,4.4vw,53px)]'

  return (
    <header data-sticky-bar className="sticky top-0 z-50 border-b border-line-1 bg-ink/84 backdrop-blur-md">
      <nav aria-label="Primary" className={`mx-auto flex h-18 items-center gap-4 ${frame}`}>
        <Link to="/" className="flex flex-none items-center gap-2.5 text-cream sm:mr-2">
          <WarrantForLaravel className="text-[19px]" />
        </Link>

        {/*
          Links that do not fit on one row wrap out of sight rather than onto a
          second one. At phone width there is no room for even the first. The
          left padding is room for the first link's ×, which the clipping would
          otherwise cut off.
        */}
        <div className="hidden h-5 min-w-0 flex-1 flex-wrap gap-x-9 gap-y-1 overflow-hidden text-sm leading-5 font-medium sm:mr-4 sm:flex sm:pl-4">
          {NAV.map((item) => {
            const active = item.label === docs?.active
            return (
              <SiteLink
                key={item.label}
                href={item.href}
                className={`relative ${active ? 'text-cream' : 'text-tan hover:text-cream'}`}
              >
                {/* The signature's ×, full coral before the page the reader is on and faded before the rest. */}
                <span
                  aria-hidden
                  className={`absolute top-0 -left-4 text-[15.5px] leading-5 font-bold text-coral ${active ? '' : 'opacity-40'}`}
                >
                  ×
                </span>
                {item.label}
              </SiteLink>
            )
          })}
        </div>

        <div className="ml-auto flex flex-none items-center gap-2.5">
          {/* At phone width the home page's header has no room for search beside its call to action; the shortcuts still open it. */}
          <div className={docs ? 'contents' : 'hidden sm:contents'}>
            <Search />
          </div>
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
      <LoadingLine />
    </header>
  )
}

/**
 * A coral line along the header's bottom edge while the next page loads. It
 * waits a moment before it starts drawing, so a page that arrives at once
 * shows nothing, and it slows as it goes, so a long load never reaches the end.
 */
function LoadingLine() {
  const loading = useRouterState({ select: (state) => state.status === 'pending' })
  if (!loading) return null

  return (
    <div
      aria-hidden
      className="absolute inset-x-0 -bottom-px h-0.5 origin-left scale-x-90 bg-coral animate-[route-loading_10s_cubic-bezier(0.05,0.7,0.1,1)_150ms_both]"
    />
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
