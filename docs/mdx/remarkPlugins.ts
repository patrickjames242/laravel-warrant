import remarkDirective from 'remark-directive'
import remarkFrontmatter from 'remark-frontmatter'
import remarkGfm from 'remark-gfm'
import { remarkDocs } from './remarkDocs.ts'

/**
 * The remark plugins a page's Markdown is read with. The MDX build and the
 * search index both use them, so the index sees exactly the page that is
 * rendered: the same blocks, and headings with the same text and so the same ids.
 */
export const remarkPlugins = [remarkFrontmatter, remarkGfm, remarkDirective, remarkDocs]
