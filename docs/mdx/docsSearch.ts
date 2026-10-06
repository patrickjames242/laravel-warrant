import { createProcessor } from '@mdx-js/mdx'
import type { Element, Root, RootContent } from 'hast'
import { toString } from 'hast-util-to-string'
import { readFileSync } from 'node:fs'
import rehypeSlug from 'rehype-slug'
import type { Plugin } from 'vite'
import { markdownFiles, readPage, reloadOnContentChange } from './docsPages.ts'
import { remarkPlugins } from './remarkPlugins.ts'

const MODULE_ID = 'virtual:docs-search'
const RESOLVED_ID = `\0${MODULE_ID}`

/**
 * One searchable part of a page: the stretch from one `h2` or `h3` to the next,
 * or, with no heading, the opening stretch above the first of them.
 */
export interface SearchSection {
  slug: string
  /** The heading's id, as the rendered page gives it. */
  id?: string
  heading?: string
  /** The section's prose as plain text. The opening section starts with the page's description. */
  text: string
  /** The source of the section's code blocks, one after another. */
  code: string
}

/** The headings a page is split into sections at. */
const SPLITS = new Set(['h2', 'h3'])

/** Elements whose text ends where the next one's begins, so the two are kept apart by a space. */
const BLOCKS = new Set(['p', 'li', 'blockquote', 'callout', 'h4', 'h5', 'h6', 'tr', 'th', 'td', 'pre'])

interface Draft {
  id?: string
  heading?: string
  text: string[]
  code: string[]
}

/** Appends the text of `node` to `into`, with code blocks' source going to its `code` rather than its `text`. */
function collect(node: RootContent, into: Draft): void {
  if (node.type === 'text') {
    into.text.push(node.value)
    return
  }
  if (node.type !== 'element') return

  // `remarkDocs` hands a fence over as an empty `codeblock` carrying its text as `source`.
  if (node.tagName === 'codeblock') {
    into.code.push(String(node.properties.source ?? ''))
    return
  }
  if (node.tagName === 'callout' && typeof node.properties.title === 'string') into.text.push(node.properties.title, ' ')

  for (const child of node.children) collect(child, into)
  if (BLOCKS.has(node.tagName)) into.text.push(' ')
}

function isSplit(node: RootContent): node is Element {
  return node.type === 'element' && SPLITS.has(node.tagName) && typeof node.properties.id === 'string'
}

function squeeze(parts: string[], separator: string): string {
  return parts.join(separator).replace(/\s+/g, ' ').trim()
}

/** Splits a rendered page into its sections, in order, starting with the opening one. */
function sectionsOf(tree: Root, slug: string, description: string): SearchSection[] {
  let current: Draft = { text: [description, ' '], code: [] }
  const drafts = [current]

  for (const node of tree.children) {
    if (isSplit(node)) {
      current = { id: String(node.properties.id), heading: toString(node), text: [], code: [] }
      drafts.push(current)
    } else {
      collect(node, current)
    }
  }

  return drafts.map((draft) => ({
    slug,
    id: draft.id,
    heading: draft.heading,
    text: squeeze(draft.text, ''),
    code: squeeze(draft.code, ' '),
  }))
}

async function readSections(root: string, file: string): Promise<SearchSection[]> {
  const { slug, description } = readPage(root, file)
  let sections: SearchSection[] = []

  // The page goes through the same Markdown and slugging as the MDX build, and
  // its sections are read from the HTML that is then compiled to JavaScript.
  const processor = createProcessor({
    format: 'md',
    remarkPlugins,
    rehypePlugins: [
      rehypeSlug,
      () => (tree: Root) => {
        sections = sectionsOf(tree, slug, description)
      },
    ],
  })
  await processor.process(readFileSync(file, 'utf8'))

  return sections
}

/**
 * Serves `virtual:docs-search`, every page under `root` split into the
 * sections the docs search looks through. The site imports it only when search
 * is first opened, so it is a chunk of its own that no page load waits for.
 */
export function docsSearch(root: string): Plugin {
  return {
    name: 'docs-search',
    resolveId(id) {
      return id === MODULE_ID ? RESOLVED_ID : undefined
    },
    async load(id) {
      if (id !== RESOLVED_ID) return undefined
      const files = markdownFiles(root)
      for (const file of files) this.addWatchFile(file)
      const sections = (await Promise.all(files.map((file) => readSections(root, file)))).flat()
      return `export const sections = ${JSON.stringify(sections)}`
    },
    configureServer(server) {
      reloadOnContentChange(server, root, RESOLVED_ID)
    },
  }
}
