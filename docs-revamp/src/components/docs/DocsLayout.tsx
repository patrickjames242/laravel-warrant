import { useEffect, useState } from 'react'
import type { DocModule } from '../../docs/content'
import { mdxComponents } from '../../docs/mdxComponents'
import type { DocPage, PageEntry } from '../../docs/nav'
import { neighbours } from '../../docs/nav'
import { links } from '../../lib/links'
import type { NavLabel } from '../SiteHeader'
import { SiteHeader } from '../SiteHeader'
import { DocLink } from './DocLink'
import { DocsSidebar } from './DocsSidebar'
import { PAGE_TOP, TableOfContents } from './TableOfContents'

interface DocsLayoutProps {
  entry: PageEntry
  content: DocModule
}

/** Which link in the site header a page belongs under. */
function headerLink(entry: PageEntry): NavLabel {
  if (entry.page.slug === 'getting-started/why-warrant') return 'Why Warrant'
  if (entry.section.label === 'Guides') return 'Guides'
  if (entry.section.label === 'Reference') return 'API'
  return 'Docs'
}

/**
 * A docs page: the sidebar, the article, and the list of its headings. Below
 * 990px the sidebar becomes a drawer behind a menu button, and below 1320px the
 * list of headings is left out.
 */
export function DocsLayout({ entry, content }: DocsLayoutProps) {
  const { page } = entry
  const [menuOpen, setMenuOpen] = useState(false)
  const group = entry.groups.at(-1)
  const toc = [{ id: PAGE_TOP, label: 'Overview' }, ...content.headings]
  const Body = content.default

  useEffect(() => {
    document.title = `${page.title} · Laravel Warrant`
  }, [page.title])

  const closeMenu = () => {
    setMenuOpen(false)
  }

  return (
    <>
      <SiteHeader docs={{ active: headerLink(entry) }} />

      <div className="sticky top-16 z-[45] flex h-12 items-center gap-3 border-b border-line-1 bg-ink/90 px-[clamp(17.5px,3.5vw,35px)] backdrop-blur-md min-[990px]:hidden">
        <button
          type="button"
          onClick={() => {
            setMenuOpen((open) => !open)
          }}
          aria-expanded={menuOpen}
          className="flex h-8 cursor-pointer items-center gap-2 rounded-md border border-line-3 px-3 font-mono text-[14px] leading-none font-medium text-sand"
        >
          <span className="text-coral">≡</span>
          Menu
        </button>
        <div className="min-w-0 truncate text-[14.5px] leading-[1.2] font-medium text-taupe">
          {group?.label} <span className="text-gutter">/</span> <span className="text-cream">{page.title}</span>
        </div>
      </div>

      <div className="mx-auto grid max-w-[1584px] items-start min-[990px]:grid-cols-[299px_minmax(0,1fr)] min-[1320px]:grid-cols-[317px_minmax(0,1fr)_264px]">
        <DocsSidebar slug={page.slug} open={menuOpen} onNavigate={closeMenu} />
        <div
          aria-hidden="true"
          onClick={closeMenu}
          className={`fixed inset-0 z-[35] bg-[rgba(8,5,4,0.6)] transition-opacity duration-300 ease-out min-[990px]:hidden ${
            menuOpen ? 'opacity-100' : 'pointer-events-none opacity-0'
          }`}
        />

        <main className="min-w-0 px-[clamp(22px,4.5vw,70.5px)] pt-[clamp(35px,4.5vw,61.5px)] pb-24">
          <article className="mx-auto max-w-[836px]">
            <PageHeader page={page} eyebrow={group?.label} />

            <div className="mt-10 [&>*]:mt-5 [&>h2]:mt-16 [&>h3]:mt-10 [&>h4]:mt-8">
              <Body components={mdxComponents} />
            </div>

            <Pager {...neighbours(page.slug)} />
          </article>
        </main>

        <TableOfContents entries={toc} />
      </div>

      <footer className="border-t border-line-1 bg-ink-deep">
        <div className="mx-auto flex max-w-[1584px] flex-wrap justify-between gap-x-8 gap-y-3 px-[clamp(17.5px,3.5vw,35px)] py-7 text-[14.5px] leading-normal text-umber">
          <span>Not an official Laravel package. MIT licensed.</span>
          <a href={links.github} className="text-taupe">
            GitHub
          </a>
        </div>
      </footer>
    </>
  )
}

function PageHeader({ page, eyebrow }: { page: DocPage; eyebrow?: string }) {
  return (
    <header>
      {eyebrow && (
        <div className="flex items-center gap-2.5 font-mono text-[13px] leading-none font-medium tracking-[.14em] text-coral uppercase">
          <span className="size-2 bg-coral" />
          {eyebrow}
        </div>
      )}
      <h1
        id={PAGE_TOP}
        className="mt-5 text-[clamp(44px,5.5vw,66px)] leading-[0.95] font-extrabold tracking-[-0.045em] text-balance text-cream"
      >
        {page.title}
      </h1>
      <p className="mt-5 text-[21px] leading-[1.55] text-pretty text-tan">{page.description}</p>
      <div className="mt-7 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-[7.5px] border border-line-3 bg-surface px-3.5 py-3 text-[15.5px] leading-normal text-tan">
        <span className="rounded-[4.5px] border border-coral px-[7.5px] py-1 font-mono text-[11.5px] leading-none font-semibold tracking-widest text-coral">
          BETA
        </span>
        <span className="flex-[1_1_280px]">
          Still being tested — expect API changes between releases.{' '}
          <a href={links.issues} className="text-cream underline underline-offset-3">
            Report an issue
          </a>
          .
        </span>
      </div>
    </header>
  )
}

function Pager({ previous, next }: { previous?: DocPage; next?: DocPage }) {
  const card =
    'grid gap-2.5 rounded-[9px] border border-line-3 px-5 py-[20px] text-cream hover:border-coral hover:text-cream'

  return (
    <nav
      aria-label="Pagination"
      className="mt-18 grid grid-cols-[repeat(auto-fit,minmax(min(100%,264px),1fr))] gap-3 border-t border-line-1 pt-6"
    >
      {previous && (
        <DocLink slug={previous.slug} className={card}>
          <span className="font-mono text-[12px] leading-none font-semibold tracking-[.12em] text-umber">← PREVIOUS</span>
          <span className="text-[20px] leading-[1.2] font-semibold tracking-[-0.01em]">{previous.title}</span>
        </DocLink>
      )}
      {next && (
        <DocLink slug={next.slug} className={`${card} text-right`}>
          <span className="font-mono text-[12px] leading-none font-semibold tracking-[.12em] text-umber">NEXT →</span>
          <span className="text-[20px] leading-[1.2] font-semibold tracking-[-0.01em]">{next.title}</span>
        </DocLink>
      )}
    </nav>
  )
}
