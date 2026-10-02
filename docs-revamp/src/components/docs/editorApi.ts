import { EDITOR_ENDPOINT, EDITOR_HEADER } from '../../docs/editorProtocol'

/** Sends a request about the page at `slug` to one of the dev server's editor endpoints. */
export async function call(
  slug: string,
  init?: RequestInit,
  endpoint = EDITOR_ENDPOINT,
): Promise<{ status: number; body: unknown }> {
  const response = await fetch(`${endpoint}?slug=${encodeURIComponent(slug)}`, {
    ...init,
    headers: { [EDITOR_HEADER]: '1', 'Content-Type': 'application/json' },
  })
  return { status: response.status, body: (await response.json()) as unknown }
}

/** The reason a failed request gives, or a stand-in when it gives none. */
export function errorText(body: unknown): string {
  return typeof body === 'object' && body !== null && 'error' in body && typeof body.error === 'string'
    ? body.error
    : 'The dev server did not say why.'
}
