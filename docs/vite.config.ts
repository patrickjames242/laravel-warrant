import mdx from '@mdx-js/rollup'
import tailwindcss from '@tailwindcss/vite'
import { tanstackRouter } from '@tanstack/router-plugin/vite'
import react from '@vitejs/plugin-react'
import { fileURLToPath } from 'node:url'
import rehypeSlug from 'rehype-slug'
import { defineConfig } from 'vite'
import checker from 'vite-plugin-checker'
import { docsEditor } from './mdx/docsEditor.ts'
import { docsPages } from './mdx/docsPages.ts'
import { docsSearch } from './mdx/docsSearch.ts'
import { rehypeHeadings } from './mdx/rehypeHeadings.ts'
import { rehypeSourceLines } from './mdx/rehypeSourceLines.ts'
import { remarkPlugins } from './mdx/remarkPlugins.ts'

/** The Markdown every docs page is rendered from. */
const CONTENT = fileURLToPath(new URL('./content', import.meta.url))

export default defineConfig(({ command }) => ({
  plugins: [
    tanstackRouter({ target: 'react', autoCodeSplitting: true }),
    docsPages(CONTENT),
    docsSearch(CONTENT),
    docsEditor(CONTENT),
    {
      enforce: 'pre',
      ...mdx({
        include: /\.md$/,
        remarkPlugins,
        // Only the dev server's page editor reads the source lines, so a build leaves them out.
        rehypePlugins: [rehypeSlug, rehypeHeadings, ...(command === 'serve' ? [rehypeSourceLines] : [])],
      }),
    },
    react({ include: /\.(md|tsx?)$/ }),
    tailwindcss(),
    checker({
      typescript: { buildMode: true },
      eslint: {
        lintCommand: 'eslint .',
        useFlatConfig: true,
      },
    }),
  ],
}))
