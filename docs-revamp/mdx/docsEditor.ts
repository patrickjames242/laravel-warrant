import { execFileSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { existsSync, readFileSync, realpathSync, statSync, writeFileSync } from 'node:fs'
import type { IncomingMessage, ServerResponse } from 'node:http'
import { join, relative, sep } from 'node:path'
import type { Plugin } from 'vite'
import type { ChangedEvent, PageSource, SaveConflict, SaveRequest, SaveResponse } from '../src/docs/editorProtocol.ts'
import {
  EDITOR_CHANGED_EVENT,
  EDITOR_ENDPOINT,
  EDITOR_HEADER,
  EDITOR_ROLLBACK_ENDPOINT,
} from '../src/docs/editorProtocol.ts'
import { slugFor } from './docsPages.ts'

/** A slug is lowercase path segments of letters, digits and hyphens, or empty for the root page. */
const SLUG = /^(?:[a-z0-9][a-z0-9-]*(?:\/[a-z0-9][a-z0-9-]*)*)?$/

/** No docs page comes near this; anything larger is not a page. */
const MAX_BODY = 2 * 1024 * 1024

class HttpError extends Error {
  constructor(
    readonly status: number,
    message: string,
  ) {
    super(message)
  }
}

function versionOf(source: string): string {
  return createHash('sha1').update(source).digest('hex')
}

/**
 * The Markdown file behind a slug: `<slug>.md`, or `<slug>/index.md`. Only an
 * existing file inside `root` is ever returned, so a request can read or
 * overwrite a docs page and nothing else.
 */
function fileFor(root: string, slug: string): string {
  if (!SLUG.test(slug)) throw new HttpError(400, `"${slug}" is not a page slug.`)

  const candidates = slug === '' ? ['index.md'] : [`${slug}.md`, join(slug, 'index.md')]
  const realRoot = realpathSync(root)

  for (const candidate of candidates) {
    const file = join(root, candidate)
    if (!existsSync(file) || !statSync(file).isFile()) continue
    const inside = relative(realRoot, realpathSync(file))
    if (inside.startsWith('..') || inside.startsWith(sep)) break
    return file
  }

  throw new HttpError(404, `There is no page at "${slug}".`)
}

/** Runs git in `root`'s repository. Arguments go straight to git, never through a shell. */
function git(root: string, args: string[]): string {
  return execFileSync('git', args, { cwd: root, encoding: 'utf8', maxBuffer: MAX_BODY * 4, stdio: ['ignore', 'pipe', 'pipe'] })
}

/** The top of the git repository `root` is in. */
function repoTop(root: string): string {
  return realpathSync(git(root, ['rev-parse', '--show-toplevel']).trim())
}

/**
 * The file's path from the top of its git repository, as git names it. Git
 * reads a path given to a command relative to where it runs, so commands that
 * take this path run from the top.
 */
function repoPath(root: string, file: string): string {
  return relative(repoTop(root), realpathSync(file)).split(sep).join('/')
}

/** The file as of the last commit, or `null` when git does not track it or there is no commit yet. */
function committedSource(root: string, file: string): string | null {
  try {
    return git(root, ['show', `HEAD:${repoPath(root, file)}`])
  } catch {
    return null
  }
}

function readSource(root: string, file: string): PageSource {
  const source = readFileSync(file, 'utf8')
  return {
    path: relative(join(root, '..'), file).split(sep).join('/'),
    source,
    version: versionOf(source),
    base: committedSource(root, file),
  }
}

/**
 * Restores the file, and its entry in the index, to the last commit: every
 * uncommitted change to it, staged or not, is gone afterwards.
 */
function rollBack(root: string, file: string): void {
  if (committedSource(root, file) === null) {
    throw new HttpError(409, 'This page has never been committed, so there is nothing to roll back to.')
  }
  git(repoTop(root), ['restore', '--source=HEAD', '--staged', '--worktree', '--', repoPath(root, file)])
}

/**
 * Refuses a request that did not come from the docs site itself: it must carry
 * the editor's header, and if the browser says where it came from, that must be
 * this server.
 */
function assertFromEditor(request: IncomingMessage): void {
  if (request.headers[EDITOR_HEADER] !== '1') throw new HttpError(403, 'Missing the editor header.')

  const origin = request.headers.origin
  if (origin !== undefined && new URL(origin).host !== request.headers.host) {
    throw new HttpError(403, 'Requests from another origin are refused.')
  }
}

async function readBody(request: IncomingMessage): Promise<SaveRequest> {
  let size = 0
  const chunks: Buffer[] = []
  for await (const chunk of request as AsyncIterable<Buffer>) {
    size += chunk.length
    if (size > MAX_BODY) throw new HttpError(413, 'The page is too large to save.')
    chunks.push(chunk)
  }

  const body: unknown = JSON.parse(Buffer.concat(chunks).toString('utf8'))
  if (
    typeof body !== 'object' ||
    body === null ||
    !('source' in body) ||
    !('version' in body) ||
    typeof body.source !== 'string' ||
    typeof body.version !== 'string'
  ) {
    throw new HttpError(400, 'A save needs `source` and `version` strings.')
  }
  return { source: body.source, version: body.version }
}

function send(response: ServerResponse, status: number, body: unknown): void {
  response.statusCode = status
  response.setHeader('Content-Type', 'application/json')
  response.setHeader('Cache-Control', 'no-store')
  response.end(JSON.stringify(body))
}

/** Answers a request for one page: checks it came from the editor, finds the page's file, and reports any failure as JSON. */
function pageRoute(
  root: string,
  handle: (request: IncomingMessage, response: ServerResponse, file: string) => Promise<void> | void,
) {
  return (request: IncomingMessage, response: ServerResponse) => {
    void (async () => {
      try {
        assertFromEditor(request)
        const url = new URL(request.url ?? '', 'http://localhost')
        await handle(request, response, fileFor(root, url.searchParams.get('slug') ?? ''))
      } catch (error) {
        const status = error instanceof HttpError ? error.status : error instanceof SyntaxError ? 400 : 500
        send(response, status, { error: error instanceof Error ? error.message : String(error) })
      }
    })()
  }
}

/**
 * Lets the docs pages be edited from the browser while the dev server runs.
 * It serves {@link EDITOR_ENDPOINT} to read and write a page's Markdown under
 * `root` and {@link EDITOR_ROLLBACK_ENDPOINT} to roll one back to the last
 * commit, and tells the browser whenever one of those files changes on disk.
 *
 * It applies only to `vite dev`: a build has no server, and so no way to write.
 */
export function docsEditor(root: string): Plugin {
  return {
    name: 'docs-editor',
    apply: 'serve',
    configureServer(server) {
      server.middlewares.use(
        EDITOR_ENDPOINT,
        pageRoute(root, async (request, response, file) => {
          if (request.method === 'GET') {
            send(response, 200, readSource(root, file))
            return
          }

          if (request.method === 'PUT') {
            const { source, version } = await readBody(request)
            const current = readSource(root, file)
            if (current.version !== version) {
              send(response, 409, { ...current, conflict: true } satisfies SaveConflict)
              return
            }
            writeFileSync(file, source)
            send(response, 200, { version: versionOf(source) } satisfies SaveResponse)
            return
          }

          throw new HttpError(405, `${request.method ?? 'That method'} is not supported.`)
        }),
      )

      server.middlewares.use(
        EDITOR_ROLLBACK_ENDPOINT,
        pageRoute(root, (request, response, file) => {
          if (request.method !== 'POST') throw new HttpError(405, 'A rollback is a POST.')
          rollBack(root, file)
          send(response, 200, readSource(root, file))
        }),
      )

      server.watcher.on('change', (file) => {
        if (!file.startsWith(root) || !file.endsWith('.md')) return
        server.ws.send({
          type: 'custom',
          event: EDITOR_CHANGED_EVENT,
          data: { slug: slugFor(root, file) } satisfies ChangedEvent,
        })
      })
    },
  }
}
