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

/** A group's key is its label and the labels of every section and group above it, joined by `/`. */
function groupKey(parent: string, group: DocGroup): string {
  return `${parent}/${group.label}`
}

/** The keys of the group a key names and of every group it sits inside. */
function withAncestors(key: string): Set<string> {
  const parts = key.split('/')
  return new Set(parts.map((_, i) => parts.slice(0, i + 1).join('/')))
}

/** The keys of every group that holds the page, outermost first. */
function groupsHolding(slug: string): Set<string> {
  const keys = new Set<string>()
  const walk = (items: readonly DocItem[], parent: string) => {
    for (const item of items) {
      if (!isGroup(item) || !contains(item.items, slug)) continue
      const key = groupKey(parent, item)
      keys.add(key)
      walk(item.items, key)
    }
  }
  for (const section of SECTIONS) walk(section.groups, section.label)
  return keys
}

/**
 * Every docs page, in the numbered sections and collapsible groups of the
 * sidebar. The groups behave as an accordion: only one run of them is open at
 * a time, the one holding the current page, until the reader opens another,
 * which closes the rest. Arriving on a page folds everything back to that
 * page's groups. The numbered sections open and close independently.
 */
export function DocsSidebar({ slug, open, onNavigate }: DocsSidebarProps) {
  const [sectionsClosed, setSectionsClosed] = useState<ReadonlySet<string>>(new Set())
  const [openGroups, setOpenGroups] = useState<ReadonlySet<string>>(() => groupsHolding(slug))
  const [shownSlug, setShownSlug] = useState(slug)
  const scroller = useRef<HTMLElement>(null)

  // A new page resets the groups during render, so the fold happens in the same frame as the navigation.
  if (slug !== shownSlug) {
    setShownSlug(slug)
    setOpenGroups(groupsHolding(slug))
  }

  const toggleGroup = (key: string) => {
    setOpenGroups((current) => {
      if (!current.has(key)) return withAncestors(key)
      const next = new Set(current)
      for (const other of current) if (other === key || other.startsWith(`${key}/`)) next.delete(other)
      return next
    })
  }

  const toggleSection = (key: string) => {
    setSectionsClosed((current) => {
      const next = new Set(current)
      if (!next.delete(key)) next.add(key)
      return next
    })
  }

  /*
   * Keep the current page's link in view when the list is taller than the
   * screen. The link's place is only known once the groups have finished
   * folding to the new page, so it is measured after the sidebar's transitions
   * settle. The first page jumps straight there; later ones glide.
   */
  const revealed = useRef(false)
  useEffect(() => {
    const aside = scroller.current
    if (!aside) return
    let cancelled = false
    const smooth = revealed.current
    revealed.current = true

    const reveal = () => {
      const link = aside.querySelector<HTMLElement>('[aria-current="page"]')
      if (cancelled || !link) return
      const top = link.getBoundingClientRect().top - aside.getBoundingClientRect().top + aside.scrollTop
      if (top < aside.scrollTop || top + link.offsetHeight > aside.scrollTop + aside.clientHeight) {
        aside.scrollTo({ top: top - aside.clientHeight / 3, behavior: smooth ? 'smooth' : 'instant' })
      }
    }

    void Promise.allSettled(aside.getAnimations({ subtree: true }).map((animation) => animation.finished)).then(reveal)
    return () => {
      cancelled = true
    }
  }, [slug])

  const renderItems = (items: readonly DocItem[], path: string) => (
    <ul className="ml-3 grid border-l border-line-2">
      {items.map((item) =>
        isGroup(item) ? (
          <li key={item.label}>{renderGroup(item, groupKey(path, item), true)}</li>
        ) : (
          <li key={item.slug}>
            <DocLink
              slug={item.slug}
              onClick={onNavigate}
              className={`-ml-px block rounded-r-[5.5px] border-l px-3 py-[7.5px] text-[15.5px] leading-[1.35] transition-colors duration-250 ease-glide hover:bg-cream/4 hover:text-cream focus-visible:outline-offset-[-2px] ${
                item.slug === slug
                  ? 'border-coral bg-coral/8 font-semibold text-cream'
                  : 'border-transparent text-fawn'
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
    const expanded = openGroups.has(key)

    return (
      <div className={nested ? 'mt-1 mb-1' : ''}>
        <button
          type="button"
          onClick={() => {
            toggleGroup(key)
          }}
          aria-expanded={expanded}
          className={`flex w-full cursor-pointer items-center justify-between gap-2 py-1.5 text-left text-[15px] leading-[1.25] font-medium transition-colors duration-250 ease-glide hover:text-cream focus-visible:outline-offset-[-2px] ${
            nested ? 'pr-2 pl-3 text-fawn' : 'pr-2 pl-3 text-sand'
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
      data-docs-sidebar
      aria-label="Documentation"
      className={`fixed top-[calc(var(--spacing)*28)] bottom-0 left-0 z-40 w-[min(352px,86vw)] overflow-y-auto border-r border-line-1 bg-ink-raised pt-6 pr-4 pb-12 pl-[clamp(17.5px,3.5vw,35px)] shadow-drawer transition-[translate,visibility] duration-450 ease-glide ${
        open ? 'visible translate-x-0' : 'invisible -translate-x-full'
      } min-[990px]:visible min-[990px]:sticky min-[990px]:top-18 min-[990px]:bottom-auto min-[990px]:h-[calc(100vh-var(--spacing)*18)] min-[990px]:w-auto min-[990px]:translate-x-0 min-[990px]:bg-transparent min-[990px]:shadow-none min-[990px]:transition-none`}
    >
      <nav className="grid gap-7">
        {SECTIONS.map((section, i) => {
          const key = section.label
          const expanded = !sectionsClosed.has(key)

          return (
            <div key={key}>
              <button
                type="button"
                onClick={() => {
                  toggleSection(key)
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
                  <div key={group.label}>{renderGroup(group, groupKey(key, group), false)}</div>
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
