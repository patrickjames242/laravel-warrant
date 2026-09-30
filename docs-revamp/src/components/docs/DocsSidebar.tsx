import type { ReactNode } from 'react'
import { useEffect, useRef, useState } from 'react'
import type { DocGroup, DocItem } from '../../docs/nav'
import { SECTIONS, isGroup, sidebarLabel } from '../../docs/nav'
import { DocLink } from './DocLink'

interface DocsSidebarProps {
  /** The page being shown. */
  slug: string
  /** Below 990px the sidebar is a drawer, shown only while this is true. */
  open: boolean
  onNavigate: () => void
}

function contains(items: readonly DocItem[], slug: string): boolean {
  return items.some((item) => (isGroup(item) ? contains(item.items, slug) : item.slug === slug))
}

/**
 * Every docs page, in the numbered sections and collapsible groups of the
 * sidebar. A group starts open when it holds the current page and closed
 * otherwise; once the reader toggles one, it stays the way they left it.
 */
export function DocsSidebar({ slug, open, onNavigate }: DocsSidebarProps) {
  const [toggled, setToggled] = useState<Partial<Record<string, boolean>>>({})
  const scroller = useRef<HTMLElement>(null)

  const isOpen = (key: string, byDefault: boolean) => toggled[key] ?? byDefault
  const toggle = (key: string, byDefault: boolean) => {
    setToggled((current) => ({ ...current, [key]: !(current[key] ?? byDefault) }))
  }

  // Keep the current page's link in view when the list is taller than the screen.
  useEffect(() => {
    const aside = scroller.current
    const link = aside?.querySelector<HTMLElement>('[aria-current="page"]')
    if (!aside || !link) return
    const top = link.offsetTop
    if (top < aside.scrollTop || top + link.offsetHeight > aside.scrollTop + aside.clientHeight) {
      aside.scrollTop = top - aside.clientHeight / 3
    }
  }, [slug])

  const renderItems = (items: readonly DocItem[], path: string) => (
    <ul className="ml-3 grid border-l border-line-2">
      {items.map((item) =>
        isGroup(item) ? (
          <li key={item.label}>{renderGroup(item, `${path}/${item.label}`, true)}</li>
        ) : (
          <li key={item.slug}>
            <DocLink
              slug={item.slug}
              onClick={onNavigate}
              className={`-ml-px block rounded-r-[5.5px] border-l px-3 py-[7.5px] text-[15.5px] leading-[1.35] transition-colors duration-250 ease-glide hover:bg-cream/4 hover:text-cream focus-visible:outline-offset-[-2px] ${
                item.slug === slug
                  ? 'border-coral bg-coral/8 font-semibold text-cream'
                  : 'border-transparent text-[#b39784]'
              }`}
              current={item.slug === slug}
            >
              {sidebarLabel(item)}
            </DocLink>
          </li>
        ),
      )}
    </ul>
  )

  const renderGroup = (group: DocGroup, key: string, nested: boolean) => {
    const byDefault = contains(group.items, slug)
    const expanded = isOpen(key, byDefault)

    return (
      <div className={nested ? 'mt-1 mb-1' : ''}>
        <button
          type="button"
          onClick={() => {
            toggle(key, byDefault)
          }}
          aria-expanded={expanded}
          className={`flex w-full cursor-pointer items-center justify-between gap-2 py-1.5 text-left text-[15px] leading-[1.25] font-medium transition-colors duration-250 ease-glide hover:text-cream focus-visible:outline-offset-[-2px] ${
            nested ? 'pr-2 pl-3 text-[#b39784]' : 'pr-2 pl-3 text-sand'
          }`}
        >
          {group.label}
          <Chevron open={expanded} />
        </button>
        <Collapse open={expanded} className="pt-1">
          {renderItems(group.items, key)}
        </Collapse>
      </div>
    )
  }

  return (
    <aside
      ref={scroller}
      aria-label="Documentation"
      className={`fixed top-[calc(var(--spacing)*28)] bottom-0 left-0 z-40 w-[min(352px,86vw)] overflow-y-auto border-r border-line-1 bg-[#140f0d] pt-6 pr-4 pb-12 pl-[clamp(17.5px,3.5vw,35px)] shadow-[26.5px_0_66px_-22px_rgba(0,0,0,.7)] transition-[translate,visibility] duration-450 ease-glide ${
        open ? 'visible translate-x-0' : 'invisible -translate-x-full'
      } min-[990px]:visible min-[990px]:sticky min-[990px]:top-16 min-[990px]:bottom-auto min-[990px]:h-[calc(100vh-var(--spacing)*16)] min-[990px]:w-auto min-[990px]:translate-x-0 min-[990px]:bg-transparent min-[990px]:shadow-none min-[990px]:transition-none`}
    >
      <nav className="grid gap-7">
        {SECTIONS.map((section, i) => {
          const key = section.label
          const expanded = isOpen(key, true)

          return (
            <div key={key}>
              <button
                type="button"
                onClick={() => {
                  toggle(key, true)
                }}
                aria-expanded={expanded}
                className="flex w-full cursor-pointer items-center justify-between gap-2 pr-2 pl-3 text-left font-mono focus-visible:outline-offset-[-2px] text-[12px] leading-none font-semibold tracking-[.12em] text-sand uppercase"
              >
                <span className="flex items-center gap-2.5">
                  <span className="font-medium text-coral">{String(i + 1).padStart(2, '0')}</span>
                  {section.label}
                </span>
                <Chevron open={expanded} />
              </button>
              <Collapse open={expanded} className="grid gap-1 pt-3">
                {section.groups.map((group) => (
                  <div key={group.label}>{renderGroup(group, `${key}/${group.label}`, false)}</div>
                ))}
              </Collapse>
            </div>
          )
        })}
      </nav>
    </aside>
  )
}

/**
 * Content that folds away to nothing. The row animates between a fraction of
 * nothing and all of its content's height, so it needs no measuring, and while
 * folded the content is inert: its links are neither focusable nor announced.
 */
function Collapse({ open, className = '', children }: { open: boolean; className?: string; children: ReactNode }) {
  return (
    <div
      inert={!open}
      className={`grid [transition:grid-template-rows_400ms_var(--ease-glide),opacity_300ms_ease-in-out] ${
        open ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'
      }`}
    >
      <div className="min-h-0 overflow-hidden">
        <div className={className}>{children}</div>
      </div>
    </div>
  )
}

function Chevron({ open }: { open: boolean }) {
  return (
    <span
      aria-hidden="true"
      className={`font-sans text-[16.5px] leading-none text-umber transition-transform duration-400 ease-glide ${open ? 'rotate-90' : ''}`}
    >
      ›
    </span>
  )
}
