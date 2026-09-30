import mdx from '@mdx-js/rollup'
import tailwindcss from '@tailwindcss/vite'
import { tanstackRouter } from '@tanstack/router-plugin/vite'
import react from '@vitejs/plugin-react'
import { fileURLToPath } from 'node:url'
import rehypeSlug from 'rehype-slug'
import remarkDirective from 'remark-directive'
import remarkFrontmatter from 'remark-frontmatter'
import remarkGfm from 'remark-gfm'
import { defineConfig, searchForWorkspaceRoot } from 'vite'
import checker from 'vite-plugin-checker'
import { docsPages } from './mdx/docsPages.ts'
import { rehypeHeadings } from './mdx/rehypeHeadings.ts'
import { remarkDocs } from './mdx/remarkDocs.ts'

/** The Markdown every docs page is rendered from, shared with the Astro site. */
const CONTENT = fileURLToPath(new URL('../docs/src/content/docs', import.meta.url))

export default defineConfig({
  plugins: [
    tanstackRouter({ target: 'react', autoCodeSplitting: true }),
    docsPages(CONTENT),
    {
      enforce: 'pre',
      ...mdx({
        include: /\.md$/,
        remarkPlugins: [remarkFrontmatter, remarkGfm, remarkDirective, remarkDocs],
        rehypePlugins: [rehypeSlug, rehypeHeadings],
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
  resolve: {
    // Pages compiled from outside this directory resolve React from here.
    dedupe: ['react', 'react-dom'],
  },
  server: {
    fs: { allow: [searchForWorkspaceRoot(process.cwd()), CONTENT] },
  },
})
