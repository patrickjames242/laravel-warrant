import MiniSearch from 'minisearch'
import { sections } from 'virtual:docs-search'
import type { PageEntry } from './nav'
import { findPage } from './nav'

type Section = (typeof sections)[number]

/** A run of a snippet's text, marked when it is one of the words searched for. */
export interface Fragment {
  text: string
  match: boolean
}

/** One section that matched, with the passage that shows why. */
export interface SearchHit {
  slug: string
  /** The heading to land on; absent for the opening of a page. */
  id?: string
  heading?: Fragment[]
  snippet: Fragment[]
  /** Whether the snippet is code rather than prose. */
  code: boolean
}

/** A page with at least one matching section, its sections in order of relevance. */
export interface SearchGroup {
  entry: PageEntry
  hits: SearchHit[]
}

/** How many pages, and how many sections of each, a search returns at most. */
const MAX_PAGES = 10
const MAX_HITS_PER_PAGE = 3

/** About how many characters of a section a snippet shows. */
const SNIPPET_LENGTH = 150

/** How far before the first match a snippet starts, so the match has some lead-in. */
const LEAD = 40

/**
 * Words are runs of letters, digits and underscores, so `$user->can()` is
 * `user` and `can`, and `Gate::authorize` is `gate` and `authorize`.
 */
function tokenize(text: string): string[] {
  return text.split(/[^\p{L}\p{N}_]+/u).filter(Boolean)
}

/** Only sections of pages the sidebar lists are searched, since no other page can be opened. */
const SEARCHABLE = sections.filter((section) => findPage(section.slug))

const index = new MiniSearch<Section & { index: number }>({
  idField: 'index',
  fields: ['title', 'heading', 'text', 'code'],
  extractField: (section, field) =>
    field === 'title' ? (findPage(section.slug)?.page.title ?? '') : String(section[field as keyof typeof section] ?? ''),
  tokenize,
  searchOptions: {
    boost: { title: 4, heading: 3, text: 1, code: 0.5 },
    prefix: true,
    // A short word misspelled is too often another word, so only longer ones are matched loosely.
    fuzzy: (term) => (term.length > 3 ? 0.2 : false),
  },
})
index.addAll(SEARCHABLE.map((section, position) => ({ ...section, index: position })))

function escape(term: string): string {
  return term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

/** A pattern for any of `terms` as a whole word, in any case. */
function wordsPattern(terms: string[]): RegExp {
  return new RegExp(`(?<![\\p{L}\\p{N}_])(?:${terms.map(escape).join('|')})(?![\\p{L}\\p{N}_])`, 'giu')
}

/** `text` cut into fragments, with each occurrence of `pattern` marked. */
function fragments(text: string, pattern: RegExp): Fragment[] {
  const out: Fragment[] = []
  let last = 0
  for (const found of text.matchAll(pattern)) {
    if (found.index > last) out.push({ text: text.slice(last, found.index), match: false })
    out.push({ text: found[0], match: true })
    last = found.index + found[0].length
  }
  if (last < text.length) out.push({ text: text.slice(last), match: false })
  return out
}

/**
 * About {@link SNIPPET_LENGTH} characters of `text`, starting a little before
 * the first match and cut at spaces, with an ellipsis wherever text is left out.
 */
function snippet(text: string, pattern: RegExp): Fragment[] {
  const first = Math.max(0, text.search(pattern))

  let start = Math.max(0, first - LEAD)
  if (start > 0) start = text.indexOf(' ', start) + 1 || start
  let end = Math.min(text.length, start + SNIPPET_LENGTH)
  if (end < text.length) end = text.lastIndexOf(' ', end) > start ? text.lastIndexOf(' ', end) : end

  const passage = `${start > 0 ? '…' : ''}${text.slice(start, end)}${end < text.length ? '…' : ''}`
  return fragments(passage, pattern)
}

function hitFor(section: Section, terms: string[], fields: Set<string>): SearchHit {
  const pattern = wordsPattern(terms)
  // The snippet comes from the prose when the prose matched, and from the code only when nothing else did.
  const code = !fields.has('text') && fields.has('code')

  return {
    slug: section.slug,
    id: section.id,
    heading: section.heading === undefined ? undefined : fragments(section.heading, pattern),
    snippet: snippet(code ? section.code : section.text, pattern),
    code,
  }
}

/**
 * The sections that match `query`, grouped by page. Pages come in the order
 * of their best section. Every word must match somewhere in a section, unless
 * no section has them all, when sections matching any of them are returned.
 */
export function search(query: string): SearchGroup[] {
  let results = index.search(query, { combineWith: 'AND' })
  if (!results.length) results = index.search(query, { combineWith: 'OR' })

  const groups = new Map<string, SearchGroup>()
  for (const result of results) {
    const section = SEARCHABLE[result.id as number]
    const entry = section && findPage(section.slug)
    if (!section || !entry) continue

    let group = groups.get(section.slug)
    if (!group) {
      if (groups.size === MAX_PAGES) continue
      group = { entry, hits: [] }
      groups.set(section.slug, group)
    }
    if (group.hits.length === MAX_HITS_PER_PAGE) continue

    const fields = new Set(Object.values(result.match).flat())
    group.hits.push(hitFor(section, result.terms, fields))
  }

  return [...groups.values()]
}
