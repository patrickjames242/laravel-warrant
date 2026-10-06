/** Every docs page split into searchable sections, read at build time by `mdx/docsSearch.ts`. */
declare module 'virtual:docs-search' {
  export const sections: {
    /** The page's path, with no leading or trailing slash. */
    slug: string
    /** The id of the heading the section starts at; absent for the stretch above a page's first heading. */
    id?: string
    heading?: string
    /** The section's prose as plain text. A page's opening section starts with its description. */
    text: string
    /** The source of the section's code blocks. */
    code: string
  }[]
}
