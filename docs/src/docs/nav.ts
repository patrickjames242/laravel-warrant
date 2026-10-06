import { pages } from 'virtual:docs-pages'

/** One documentation page. `slug` is its path, with no leading or trailing slash. */
export type DocPage = (typeof pages)[number]

/** A collapsible run of pages in the sidebar, which may nest further groups. */
export interface DocGroup {
  label: string
  items: DocItem[]
}

export type DocItem = DocPage | DocGroup

/** One of the numbered top-level divisions of the sidebar. */
export interface DocSection {
  label: string
  groups: DocGroup[]
}

export function isGroup(item: DocItem): item is DocGroup {
  return 'items' in item
}

/**
 * A group is either every page under a directory, ordered by each page's
 * `sidebar.order`, or an explicit list of page slugs and nested groups.
 */
type GroupSpec = { label: string; directory: string } | { label: string; items: (string | GroupSpec)[] }

const SIDEBAR: { label: string; groups: GroupSpec[] }[] = [
  {
    label: 'Start here',
    groups: [
      { label: 'Introduction', directory: 'getting-started' },
      { label: 'Your first rule', directory: 'first-rule' },
    ],
  },
  {
    label: 'Guides',
    groups: [
      {
        label: 'Concepts',
        items: [
          'concepts/rules-compile-to-sql',
          'concepts/rules-and-abilities',
          'concepts/row-identity',
          'concepts/three-truth-values',
          'concepts/grants-and-denials',
          'concepts/how-a-decision-is-made',
          { label: 'Context', directory: 'concepts/context' },
          'concepts/frames',
          'concepts/reachability',
          'concepts/schemas-as-vocabulary',
          'concepts/what-rules-cannot-do',
        ],
      },
      { label: 'Writing rules', directory: 'rules' },
      { label: 'Defining schemas', directory: 'schemas' },
      { label: 'Supplying rules', directory: 'supplying-rules' },
      { label: 'Asking questions', directory: 'checking' },
      { label: 'Recipes', directory: 'recipes' },
    ],
  },
  {
    label: 'Going deeper',
    groups: [
      { label: 'Understanding the SQL', directory: 'sql' },
      { label: 'Testing', directory: 'testing' },
      { label: 'Running it in production', directory: 'production' },
      { label: 'Editor tooling', directory: 'editors' },
      { label: "When something's wrong", directory: 'diagnosis' },
    ],
  },
  {
    label: 'Reference',
    groups: [
      { label: 'API reference', directory: 'reference' },
      { label: 'Roadmap', directory: 'roadmap' },
    ],
  },
]

const PAGE_BY_SLUG = new Map(pages.map((page) => [page.slug, page]))

function buildGroup(spec: GroupSpec): DocGroup {
  if ('directory' in spec) {
    const inside = pages
      .filter((page) => page.slug === spec.directory || page.slug.startsWith(`${spec.directory}/`))
      .sort((a, b) => a.order - b.order || a.title.localeCompare(b.title))
    if (!inside.length) throw new Error(`The sidebar group "${spec.label}" names ${spec.directory}/, which has no pages.`)
    return { label: spec.label, items: inside }
  }

  return {
    label: spec.label,
    items: spec.items.map((item) => {
      if (typeof item !== 'string') return buildGroup(item)
      const page = PAGE_BY_SLUG.get(item)
      if (!page) throw new Error(`The sidebar group "${spec.label}" lists ${item}, which is not a page.`)
      return page
    }),
  }
}

export const SECTIONS: DocSection[] = SIDEBAR.map((section) => ({
  label: section.label,
  groups: section.groups.map(buildGroup),
}))

/** A page, with the chain of sidebar headings above it. */
export interface PageEntry {
  page: DocPage
  section: DocSection
  /** Outermost first. */
  groups: DocGroup[]
}

function collect(section: DocSection, items: DocItem[], groups: DocGroup[], into: PageEntry[]) {
  for (const item of items) {
    if (isGroup(item)) collect(section, item.items, [...groups, item], into)
    else into.push({ page: item, section, groups })
  }
}

/** Every page, in the order the sidebar lists them. */
export const PAGES: PageEntry[] = []
for (const section of SECTIONS) {
  for (const group of section.groups) collect(section, group.items, [group], PAGES)
}

const BY_SLUG = new Map(PAGES.map((entry, index) => [entry.page.slug, { entry, index }]))

export function findPage(slug: string): PageEntry | undefined {
  return BY_SLUG.get(slug)?.entry
}

/** The pages before and after this one, in sidebar order. */
export function neighbours(slug: string): { previous?: DocPage; next?: DocPage } {
  const found = BY_SLUG.get(slug)
  if (!found) return {}
  return { previous: PAGES[found.index - 1]?.page, next: PAGES[found.index + 1]?.page }
}

/** The path a page is served at. */
export function pagePath(slug: string): string {
  return `/${slug}/`
}

export function sidebarLabel(page: DocPage): string {
  return page.label ?? page.title
}
