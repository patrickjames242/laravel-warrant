/** The frontmatter of every docs page, read at build time by `mdx/docsPages.ts`. */
declare module 'virtual:docs-pages' {
  export const pages: {
    /** The page's path, with no leading or trailing slash. */
    slug: string
    title: string
    /** A shorter name for the sidebar, when the title is too long to sit there. */
    label?: string
    description: string
    /** Where the page sits among its siblings; lower comes first. */
    order: number
  }[]
}
