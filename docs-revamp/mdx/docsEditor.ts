import { execFileSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { existsSync, readFileSync, realpathSync, statSync, writeFileSync } from 'node:fs'
import type { IncomingMessage, ServerResponse } from 'node:http'
import { join, relative, sep } from 'node:path'
import type { Plugin } from 'vite'
import type {
  ChangedEvent,
  ChangeKind,
  ChangesResponse,
  CommitRequest,
  CommitResponse,
  FileChange,
  PageSource,
  SaveConflict,
  SaveRequest,
  SaveResponse,
} from '../src/docs/editorProtocol.ts'
import {
  EDITOR_CHANGED_EVENT,
  EDITOR_CHANGES_ENDPOINT,
  EDITOR_COMMIT_ENDPOINT,
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
 * Runs git in `root`'s repository for a command whose failure the reader needs
 * to see, such as a commit a hook refused, reporting git's own explanation.
 */
function gitOrExplain(root: string, args: string[]): string {
  try {
    return git(root, args)
  } catch (error) {
    const stderr = (error as { stderr?: unknown }).stderr
    const said = (typeof stderr === 'string' ? stderr : Buffer.isBuffer(stderr) ? stderr.toString('utf8') : '').trim()
    throw new HttpError(409, said || (error instanceof Error ? error.message : String(error)))
  }
}

function kindOf(status: string): ChangeKind {
  if (status === '??') return 'untracked'
  if (status.includes('U') || status === 'AA' || status === 'DD') return 'conflicted'
  if (status.includes('R')) return 'renamed'
  if (status.includes('D')) return 'deleted'
  if (status.includes('A')) return 'added'
  return 'modified'
}

/**
 * Every file in the repository that differs from the last commit, staged or
 * not: untracked files are listed one by one, and ignored ones are left out. A
 * file is a docs page when it is Markdown under `root`.
 */
function changesIn(root: string): FileChange[] {
  const top = repoTop(root)
  const docsDir = relative(top, realpathSync(root)).split(sep).join('/') + '/'
  const fields = git(top, ['status', '--porcelain=v1', '-z', '--untracked-files=all']).split('\0')
  const changes: FileChange[] = []
  for (let i = 0; i < fields.length; i++) {
    const field = fields[i]
    if (!field) continue
    const status = field.slice(0, 2)
    const path = field.slice(3)
    const kind = kindOf(status)
    const change: FileChange = { path, kind, docs: path.startsWith(docsDir) && path.endsWith('.md') }
    // A rename or a copy is followed by the path the file had before.
    if (status.includes('R') || status.includes('C')) {
      const from = fields[++i]
      if (from) change.from = from
    }
    changes.push(change)
  }
  return changes
}

/**
 * Commits exactly the named changes, each of which must still be uncommitted,
 * and nothing else: changes already staged but not named stay staged and out
 * of the commit. A rename takes the path it left with it.
 */
function commit(root: string, request: CommitRequest): CommitResponse {
  const top = repoTop(root)
  const changes = new Map(changesIn(root).map((change) => [change.path, change]))
  const chosen = request.paths.map((path) => {
    const change = changes.get(path)
    if (!change) throw new HttpError(409, `${path} has no uncommitted change; the list may be out of date.`)
    if (change.kind === 'conflicted') throw new HttpError(409, `${path} has an unresolved merge conflict.`)
    return change
  })

  // Paths are taken literally, so a name with `*` or `:` in it is never read as a pattern.
  const literal = ['--literal-pathspecs']
  const paths = chosen.map((change) => change.path)
  const named = chosen.flatMap((change) => (change.from ? [change.path, change.from] : [change.path]))
  gitOrExplain(top, [...literal, 'add', '--all', '--', ...paths])
  gitOrExplain(top, [...literal, 'commit', '--message', request.message, '--', ...named])
  const hash = git(top, ['rev-parse', '--short', 'HEAD']).trim()

  if (!request.push) return { commit: hash, pushed: false }
  try {
    gitOrExplain(top, ['push'])
    return { commit: hash, pushed: true }
  } catch (error) {
    return { commit: hash, pushed: false, pushError: error instanceof Error ? error.message : String(error) }
  }
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

async function readJson(request: IncomingMessage): Promise<unknown> {
  let size = 0
  const chunks: Buffer[] = []
  for await (const chunk of request as AsyncIterable<Buffer>) {
    size += chunk.length
    if (size > MAX_BODY) throw new HttpError(413, 'The request is too large.')
    chunks.push(chunk)
  }
  return JSON.parse(Buffer.concat(chunks).toString('utf8'))
}

async function readSave(request: IncomingMessage): Promise<SaveRequest> {
  const body = await readJson(request)
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

async function readCommit(request: IncomingMessage): Promise<CommitRequest> {
  const body = await readJson(request)
  if (
    typeof body !== 'object' ||
    body === null ||
    !('message' in body) ||
    !('paths' in body) ||
    !('push' in body) ||
    typeof body.message !== 'string' ||
    typeof body.push !== 'boolean' ||
    !Array.isArray(body.paths) ||
    !body.paths.every((path): path is string => typeof path === 'string')
  ) {
    throw new HttpError(400, 'A commit needs a `message` string, a `paths` array of strings and a `push` boolean.')
  }
  const message = body.message.trim()
  if (message === '') throw new HttpError(400, 'A commit needs a message.')
  if (body.paths.length === 0) throw new HttpError(400, 'Choose at least one file to commit.')
  return { message, paths: body.paths, push: body.push }
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
 * `root`, {@link EDITOR_ROLLBACK_ENDPOINT} to roll one back to the last commit,
 * and {@link EDITOR_CHANGES_ENDPOINT} and {@link EDITOR_COMMIT_ENDPOINT} to list
 * the repository's uncommitted changes and commit a choice of them, and tells
 * the browser whenever one of the pages' files changes on disk.
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
            const { source, version } = await readSave(request)
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

      server.middlewares.use(
        EDITOR_CHANGES_ENDPOINT,
        pageRoute(root, (request, response, file) => {
          if (request.method !== 'GET') throw new HttpError(405, 'The changes are read with a GET.')
          send(response, 200, {
            changes: changesIn(root),
            branch: git(root, ['rev-parse', '--abbrev-ref', 'HEAD']).trim(),
            page: repoPath(root, file),
          } satisfies ChangesResponse)
        }),
      )

      server.middlewares.use(
        EDITOR_COMMIT_ENDPOINT,
        pageRoute(root, async (request, response) => {
          if (request.method !== 'POST') throw new HttpError(405, 'A commit is a POST.')
          send(response, 200, commit(root, await readCommit(request)))
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
