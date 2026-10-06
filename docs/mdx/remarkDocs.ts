import type { Paragraph, PhrasingContent, Root } from 'mdast'
import type {} from 'mdast-util-directive'
import { toString } from 'mdast-util-to-string'
import { SKIP, visit } from 'unist-util-visit'

/** The `:::kind` blocks the docs write, each drawn as a callout. */
const CALLOUTS = new Set(['note', 'tip', 'caution', 'danger'])

function isLabel(node: unknown): node is Paragraph {
  const paragraph = node as Partial<Paragraph> | undefined
  return paragraph?.type === 'paragraph' && paragraph.data?.directiveLabel === true
}

/**
 * Hands the parts of a page that the site draws itself to components of its
 * own, by element name:
 *
 * - a `:::note[Title]` block becomes a `callout` element with `kind` and
 *   `title`;
 * - a fenced code block becomes a `codeblock` element carrying its `language`,
 *   its `meta` string, and its text as `source`, so the component highlights it
 *   rather than receiving pre-rendered markup.
 *
 * Directive syntax is only meaningful as a callout. Any other `:name` in the
 * text, such as the `:view` in `can:view`, is put back as the text it was.
 */
export function remarkDocs() {
  return (tree: Root) => {
    visit(tree, (node, index, parent) => {
      if (node.type === 'code') {
        node.data = {
          hName: 'codeblock',
          hProperties: { language: node.lang ?? '', meta: node.meta ?? '', source: node.value },
          hChildren: [],
        }
        return SKIP
      }

      if (node.type === 'containerDirective' && CALLOUTS.has(node.name)) {
        const [first] = node.children
        const title = isLabel(first) ? toString(first) : undefined
        if (title !== undefined) node.children.shift()
        node.data = { hName: 'callout', hProperties: { kind: node.name, title } }
        return undefined
      }

      if ((node.type === 'textDirective' || node.type === 'leafDirective') && parent && index !== undefined) {
        const text: PhrasingContent[] = [{ type: 'text', value: `:${node.name}` }, ...node.children]
        const replacement = node.type === 'leafDirective' ? [{ type: 'paragraph' as const, children: text }] : text
        parent.children.splice(index, 1, ...(replacement as typeof parent.children))
        return [SKIP, index + replacement.length]
      }

      return undefined
    })
  }
}
