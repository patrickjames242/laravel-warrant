import { useSyncExternalStore } from 'react'

/**
 * The id of a page's title. The slugger that gives headings their ids strips
 * colons, so no heading can be given this one.
 */
export const PAGE_TOP = 'page:top'

export interface TocEntry {
  id: string
  label: string
}

/** A heading counts as reached once its top is within this far of the viewport's. */
const REACHED = 154

function subscribe(onChange: () => void) {
  window.addEventListener('scroll', onChange, { passive: true })
  window.addEventListener('resize', onChange)
  return () => {
    window.removeEventListener('scroll', onChange)
    window.removeEventListener('resize', onChange)
  }
}

/**
 * The last heading the reader has scrolled past, or the last heading of all
 * once the page is scrolled to the bottom, where later headings can never
 * reach the top.
 */
function activeHeading(entries: readonly TocEntry[]): string | undefined {
  const atBottom = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4
  if (atBottom) return entries.at(-1)?.id

  let active = entries[0]?.id
  for (const entry of entries) {
    const heading = document.getElementById(entry.id)
    if (heading && heading.getBoundingClientRect().top < REACHED) active = entry.id
  }
  return active
}

export function TableOfContents({ entries }: { entries: readonly TocEntry[] }) {
  const active = useSyncExternalStore(
    subscribe,
    () => activeHeading(entries),
    () => entries[0]?.id,
  )

  return (
    <aside
      data-docs-contents
      aria-label="On this page"
      className="sticky top-16 hidden h-[calc(100vh-var(--spacing)*16)] overflow-y-auto pt-14 pr-[clamp(17.5px,3.5vw,35px)] pb-12 pl-2 min-[1320px]:block"
    >
      <div className="font-mono text-[12px] leading-none font-semibold tracking-[.12em] text-sand">ON THIS PAGE</div>
      <ul className="mt-[20px] grid border-l border-line-2">
        {entries.map((entry) => {
          const on = entry.id === active
          return (
            <li key={entry.id}>
              <a
                href={`#${entry.id}`}
                className={`-ml-px block border-l py-1.5 pl-3.5 text-[15px] leading-[1.35] transition-colors duration-200 hover:text-cream ${
                  on ? 'border-coral font-medium text-cream' : 'border-transparent text-taupe'
                }`}
              >
                {entry.label}
              </a>
            </li>
          )
        })}
      </ul>
      <a href={`#${PAGE_TOP}`} className="mt-6 inline-block font-mono text-[13px] leading-none font-medium text-umber">
        ↑ Back to top
      </a>
    </aside>
  )
}
