/**
 * What the in-browser page editor and the dev server's `mdx/docsEditor.ts`
 * plugin say to each other. The editor exists only under `vite dev`; nothing
 * here is reachable from a production build.
 */

/** Takes a page's slug as `?slug=`. GET reads the page's Markdown; PUT writes it. */
export const EDITOR_ENDPOINT = '/__docs-editor/page'

/**
 * Takes a page's slug as `?slug=`. POST rolls the page's file back to the last
 * commit, discarding every uncommitted change to it, staged or not, and
 * answers with the page as it then is.
 */
export const EDITOR_ROLLBACK_ENDPOINT = '/__docs-editor/rollback'

/**
 * Takes the open page's slug as `?slug=`. GET lists every uncommitted change in
 * the repository, untracked files included and ignored ones left out.
 */
export const EDITOR_CHANGES_ENDPOINT = '/__docs-editor/changes'

/**
 * Takes the open page's slug as `?slug=`. POST commits the changes it names,
 * and only those, then pushes the branch if asked to.
 */
export const EDITOR_COMMIT_ENDPOINT = '/__docs-editor/commit'

/**
 * Every request carries this header. A page on another origin cannot add a
 * custom header without a CORS preflight the dev server does not grant, so a
 * site open in the same browser cannot write to the docs.
 */
export const EDITOR_HEADER = 'x-docs-editor'

/** Sent over the dev server's socket when a page's file changes on disk, whoever changed it. */
export const EDITOR_CHANGED_EVENT = 'docs-editor:changed'

/** A page's Markdown as it is on disk. */
export interface PageSource {
  /** The file's path, relative to the project. */
  path: string
  source: string
  /** A hash of `source`, sent back with a save to show which text the edit started from. */
  version: string
  /** The file as of the last commit, to mark what has changed since; `null` when git does not track it. */
  base: string | null
}

/** The body of a PUT. */
export interface SaveRequest {
  source: string
  /** The version the edit started from. The save is refused if the file has moved on since. */
  version: string
}

/** The response to a PUT that succeeded. */
export interface SaveResponse {
  version: string
}

/** The response to a PUT refused because the file changed on disk since `version`. */
export interface SaveConflict extends PageSource {
  conflict: true
}

export interface ChangedEvent {
  slug: string
}

export type ChangeKind = 'modified' | 'added' | 'deleted' | 'renamed' | 'untracked' | 'conflicted'

/** One file that differs from the last commit. */
export interface FileChange {
  /** The file's path from the top of the repository, as git names it. Changes are named by it in a commit. */
  path: string
  /** For a rename, the path the file had before. */
  from?: string
  kind: ChangeKind
  /** The file is one of the docs' Markdown pages. */
  docs: boolean
}

/** The response to a GET of {@link EDITOR_CHANGES_ENDPOINT}. */
export interface ChangesResponse {
  changes: FileChange[]
  /** The branch a commit lands on. */
  branch: string
  /** The open page's file, as a {@link FileChange.path}. */
  page: string
}

/** The body of a POST to {@link EDITOR_COMMIT_ENDPOINT}. */
export interface CommitRequest {
  message: string
  /** The changes to commit, by {@link FileChange.path}. Every one must still be an uncommitted change. */
  paths: string[]
  push: boolean
}

/** The response to a commit that was made. A push that failed leaves the commit in place and says why. */
export interface CommitResponse {
  /** The new commit's abbreviated hash. */
  commit: string
  pushed: boolean
  pushError?: string
}
