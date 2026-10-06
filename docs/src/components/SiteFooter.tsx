import { links } from '../lib/links'
import { Logo } from './Logo'
import { SiteLink } from './SiteLink'

const COLUMNS = [
  {
    title: 'START',
    items: [
      { label: 'Why Warrant', href: links.whyWarrant },
      { label: 'Installation', href: links.installation },
      { label: 'Quick start', href: links.quickStart },
      { label: 'Core concepts', href: links.coreConcepts },
    ],
  },
  {
    title: 'GUIDES',
    items: [
      { label: 'Rule language', href: links.ruleLanguage },
      { label: 'Providing rules', href: links.providers },
      { label: 'Checking access', href: links.checkingAccess },
      { label: 'How it compiles', href: links.howItCompiles },
    ],
  },
  {
    title: 'REFERENCE',
    items: [
      { label: 'API cheat sheet', href: links.cheatSheet },
      { label: 'Errors', href: links.errors },
      { label: 'GitHub', href: links.github },
    ],
  },
]

export function SiteFooter() {
  return (
    <footer className="border-t border-line-1 bg-ink-deep">
      <div className="mx-auto flex max-w-330 flex-wrap justify-between gap-x-16 gap-y-10 px-[clamp(22px,4.4vw,53px)] pt-14 pb-10">
        <div className="max-w-95">
          <Logo label="Laravel Warrant" />
          <p className="mt-4.5 text-[15px] leading-[1.6] text-taupe">
            Not an official Laravel package. Laravel Warrant is an independent, open-source project and is not
            affiliated with, maintained by, or endorsed by the Laravel team. MIT licensed.
          </p>
        </div>
        <div className="flex flex-wrap gap-x-16 gap-y-10 text-sm leading-none">
          {COLUMNS.map((column) => (
            <div key={column.title} className="grid content-start gap-3.5">
              <div className="font-mono text-[12px] leading-none font-semibold tracking-[.12em] text-umber">
                {column.title}
              </div>
              {column.items.map((item) => (
                <SiteLink key={item.label} href={item.href} className="text-tan">
                  {item.label}
                </SiteLink>
              ))}
            </div>
          ))}
        </div>
      </div>
    </footer>
  )
}
