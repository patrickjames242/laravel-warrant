import type { Language, TokenKind } from '../lib/highlight'
import { highlight } from '../lib/highlight'

const TOKEN_CLASS: Record<TokenKind, string> = {
  keyword: 'text-code-keyword',
  ability: 'text-code-ability',
  condition: 'text-cream',
  variable: 'text-code-variable',
  function: 'text-cream',
  class: 'text-code-class',
  string: 'text-code-string',
  comment: 'text-code-comment',
  punctuation: 'text-code-punctuation',
  number: 'text-code-string',
  text: 'text-sand',
}

const WEIGHT_CLASS = { 400: 'font-normal', 500: 'font-medium', 600: 'font-semibold' } as const

interface CodeLinesProps {
  source: string
  language: Language
  /** Show a line-number gutter of this width in pixels. */
  gutter?: number
  /** Zero-based indexes of lines to highlight with a coral bar. */
  highlighted?: readonly number[]
  className?: string
}

/**
 * Highlighted source, one row per line. Rows never wrap: the parent is expected
 * to scroll horizontally when a line is wider than it.
 */
export function CodeLines({ source, language, gutter, highlighted = [], className = '' }: CodeLinesProps) {
  const lines = highlight(source, language)
  const on = new Set(highlighted)

  return (
    <div className={`overflow-x-auto overflow-y-hidden font-mono ${className}`}>
      {lines.map((line, i) => (
        <div
          key={i}
          className={`flex min-h-[1lh] min-w-max pr-5 transition-[background,box-shadow] duration-300 ${
            gutter === undefined ? 'pl-3.5' : ''
          } ${on.has(i) ? 'bg-coral/9 shadow-[inset_2px_0_0_var(--color-coral)]' : ''}`}
        >
          {gutter !== undefined && (
            <span className="flex-none pr-4 text-right text-gutter select-none" style={{ width: gutter }}>
              {i + 1}
            </span>
          )}
          <span className="whitespace-pre">
            {line.tokens.map((token, j) => (
              <span key={j} className={`${TOKEN_CLASS[token.kind]} ${WEIGHT_CLASS[token.weight]}`}>
                {token.text}
              </span>
            ))}
          </span>
        </div>
      ))}
    </div>
  )
}
