import type { Root } from 'hast'
import { visit } from 'unist-util-visit'

/**
 * The elements a page is read by, block by block: what the page editor lines
 * its scroll position up against. `codeblock` and `callout` are the elements
 * `remarkDocs` makes of fences and `:::` blocks.
 */
const BLOCKS = new Set([
  'p',
  'h1',
  'h2',
  'h3',
  'h4',
  'h5',
  'h6',
  'ul',
  'ol',
  'li',
  'table',
  'tr',
  'blockquote',
  'hr',
  'codeblock',
  'callout',
])

/**
 * Marks each block element with `data-source-line`, the line of the page's Markdown
 * file it starts on, counting from 1 at the top of the file with the
 * frontmatter included. The in-browser editor reads these to keep its scroll
 * position and the article's in step.
 */
export function rehypeSourceLines() {
  return (tree: Root) => {
    visit(tree, 'element', (node) => {
      const line = node.position?.start.line
      if (line !== undefined && BLOCKS.has(node.tagName)) node.properties.dataSourceLine = line
    })
  }
}
