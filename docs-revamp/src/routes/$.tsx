import { createFileRoute, notFound } from '@tanstack/react-router'
import { DocsLayout } from '../components/docs/DocsLayout'
import { loadContent } from '../docs/content'
import { findPage } from '../docs/nav'

/** Every docs page, found by its path in the nav. */
export const Route = createFileRoute('/$')({
  loader: async ({ params }) => {
    const slug = params._splat?.replace(/^\/+|\/+$/g, '') ?? ''
    const entry = findPage(slug)
    const content = entry && (await loadContent(slug))
    // The router recognises what notFound() returns and renders its not-found page.
    // eslint-disable-next-line @typescript-eslint/only-throw-error
    if (!entry || !content) throw notFound()
    return { entry, content }
  },
  component: DocPage,
})

function DocPage() {
  const { entry, content } = Route.useLoaderData()
  return <DocsLayout entry={entry} content={content} />
}
