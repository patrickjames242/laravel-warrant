import type { MDXComponents } from 'mdx/types'
import {
  Anchor,
  Callout,
  CodeBlock,
  Em,
  H2,
  H3,
  H4,
  InlineCode,
  Li,
  Ol,
  P,
  Quote,
  Rule,
  Strong,
  Table,
  Td,
  Th,
  Ul,
} from '../components/docs/prose'

/** What a docs page's Markdown renders into, element by element. */
export const mdxComponents: MDXComponents = {
  p: P,
  a: Anchor,
  strong: Strong,
  em: Em,
  code: InlineCode,
  h2: H2,
  h3: H3,
  h4: H4,
  blockquote: Quote,
  hr: Rule,
  ul: Ul,
  ol: Ol,
  li: Li,
  table: Table,
  th: Th,
  td: Td,
  // The two elements remarkDocs turns fences and `:::` blocks into.
  codeblock: CodeBlock,
  callout: Callout,
}
