import type { MDXContent } from 'mdx/types'
import type { TocEntry } from '../components/docs/TableOfContents'

/** A compiled page: its body, and its `h2` headings for the on-this-page list. */
export interface DocModule {
  default: MDXContent
  headings: TocEntry[]
}

const PREFIX = '../../content/'

/** Each page's Markdown, compiled by the MDX plugin into a chunk of its own. */
const MODULES = import.meta.glob<DocModule>('../../content/**/*.md')

const BY_SLUG = new Map(
  Object.entries(MODULES).map(([path, load]) => [
    path
      .slice(PREFIX.length)
      .replace(/\.md$/, '')
      .replace(/(^|\/)index$/, ''),
    load,
  ]),
)

export function loadContent(slug: string): Promise<DocModule> | undefined {
  return BY_SLUG.get(slug)?.()
}
