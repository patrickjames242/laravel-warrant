/**
 * What the in-browser page editor and the dev server's `mdx/docsEditor.ts`
 * plugin say to each other. The editor exists only under `vite dev`; nothing
 * here is reachable from a production build.
 */

/** Takes a page's slug as `?slug=`. GET reads the page's Markdown; PUT writes it. */
export const EDITOR_ENDPOINT = '/__docs-editor/page'

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
