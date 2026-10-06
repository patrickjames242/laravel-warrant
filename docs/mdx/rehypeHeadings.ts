import type { Root } from 'hast'
import { toString } from 'hast-util-to-string'
import type {} from 'mdast-util-mdxjs-esm'
import { valueToEstree } from 'estree-util-value-to-estree'
import { visit } from 'unist-util-visit'

/** One entry in a page's list of headings. */
export interface Heading {
  id: string
  label: string
}

/**
 * Exports `headings` from each page: the id and text of every `h2`, in order,
 * for the on-this-page list. It runs after the ids are assigned, so an entry
 * links to exactly the anchor its heading carries.
 */
export function rehypeHeadings() {
  return (tree: Root) => {
    const headings: Heading[] = []

    visit(tree, 'element', (node) => {
      const { id } = node.properties
      if (node.tagName === 'h2' && typeof id === 'string') headings.push({ id, label: toString(node) })
    })

    tree.children.unshift({
      type: 'mdxjsEsm',
      value: '',
      data: {
        estree: {
          type: 'Program',
          sourceType: 'module',
          body: [
            {
              type: 'ExportNamedDeclaration',
              specifiers: [],
              attributes: [],
              source: null,
              declaration: {
                type: 'VariableDeclaration',
                kind: 'const',
                declarations: [
                  {
                    type: 'VariableDeclarator',
                    id: { type: 'Identifier', name: 'headings' },
                    init: valueToEstree(headings),
                  },
                ],
              },
            },
          ],
        },
      },
    })
  }
}
