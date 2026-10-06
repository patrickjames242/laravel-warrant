import { readFileSync, readdirSync } from 'node:fs'
import { join, relative, sep } from 'node:path'
import type { Plugin, ViteDevServer } from 'vite'
import { parse } from 'yaml'

const MODULE_ID = 'virtual:docs-pages'
const RESOLVED_ID = `\0${MODULE_ID}`

/** What a page's frontmatter says about it. */
export interface PageMeta {
  slug: string
  title: string
  label?: string
  description: string
  order: number
}

interface Frontmatter {
  title?: unknown
  description?: unknown
  sidebar?: { order?: unknown; label?: unknown }
}

/** Every Markdown file under `directory`, at any depth. */
export function markdownFiles(directory: string): string[] {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = join(directory, entry.name)
    if (entry.isDirectory()) return markdownFiles(path)
    return entry.name.endsWith('.md') ? [path] : []
  })
}

/** A page's path on the site: its file's path with `.md` dropped, and `index` standing for its directory. */
export function slugFor(root: string, file: string): string {
  return relative(root, file)
    .split(sep)
    .join('/')
    .replace(/\.md$/, '')
    .replace(/(^|\/)index$/, '')
}

export function readPage(root: string, file: string): PageMeta {
  const source = readFileSync(file, 'utf8')
  const block = /^---\r?\n([\s\S]*?)\r?\n---/.exec(source)
  const data = (block ? parse(block[1]) : {}) as Frontmatter
  const where = relative(root, file)

  if (typeof data.title !== 'string') throw new Error(`${where} has no title in its frontmatter.`)
  if (typeof data.description !== 'string') throw new Error(`${where} has no description in its frontmatter.`)

  return {
    slug: slugFor(root, file),
    title: data.title,
    label: typeof data.sidebar?.label === 'string' ? data.sidebar.label : undefined,
    description: data.description,
    order: typeof data.sidebar?.order === 'number' ? data.sidebar.order : Number.MAX_SAFE_INTEGER,
  }
}

/**
 * Serves `virtual:docs-pages`, the frontmatter of every Markdown page under
 * `root`, read when the module is loaded. The pages' bodies are imported on
 * their own, one chunk each, so the list costs nothing but the metadata.
 */
export function docsPages(root: string): Plugin {
  return {
    name: 'docs-pages',
    resolveId(id) {
      return id === MODULE_ID ? RESOLVED_ID : undefined
    },
    load(id) {
      if (id !== RESOLVED_ID) return undefined
      const files = markdownFiles(root)
      for (const file of files) this.addWatchFile(file)
      const pages = files.map((file) => readPage(root, file))
      return `export const pages = ${JSON.stringify(pages)}`
    },
    configureServer(server) {
      // A page added, removed or retitled changes the list, not just that page.
      reloadOnContentChange(server, root, RESOLVED_ID)
    },
  }
}

/**
 * Reloads the module `id` whenever a Markdown file under `root` is added,
 * removed or changed, for a module built from every page at once.
 */
export function reloadOnContentChange(server: ViteDevServer, root: string, id: string): void {
  const refresh = (file: string) => {
    if (!file.startsWith(root) || !file.endsWith('.md')) return
    const module = server.moduleGraph.getModuleById(id)
    if (module) void server.reloadModule(module)
  }
  server.watcher.add(root)
  server.watcher.on('add', refresh)
  server.watcher.on('unlink', refresh)
  server.watcher.on('change', refresh)
}
