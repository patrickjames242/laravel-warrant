import type { ComponentProps, ReactNode } from 'react'
import type { Language } from '../../lib/highlight'
import { useCopy } from '../../lib/useCopy'
import { CodeLines } from '../CodeLines'
import { SiteLink } from '../SiteLink'

/*
 * What each element of a docs page's Markdown is drawn as. The page body
 * spaces its children out, so none of these carry an outer margin of their own.
 *
 * Under the dev server each block arrives with `data-source-line`, the line of the
 * Markdown it came from, and passes it to the element it draws so the page
 * editor can line the article up with the source.
 */

/** The source line a block element carries under the dev server. */
interface SourceLine {
  'data-source-line'?: number | string
}

export function P({ children, 'data-source-line': line }: ComponentProps<'p'> & SourceLine) {
  return <p data-source-line={line} className="text-[18px] leading-[1.7] text-pretty text-tan">{children}</p>
}

export function Strong({ children }: ComponentProps<'strong'>) {
  return <strong className="font-semibold text-cream">{children}</strong>
}

export function Em({ children }: ComponentProps<'em'>) {
  return <em className="text-sand italic">{children}</em>
}

export function InlineCode({ children }: ComponentProps<'code'>) {
  return (
    <code className="rounded-[4.5px] border border-line-3 bg-surface px-[6.5px] py-[2px] font-mono text-[0.85em] leading-none font-medium text-peach">
      {children}
    </code>
  )
}

const LINK = 'text-coral-soft underline underline-offset-3 hover:text-coral'

/** A link in a page's Markdown, which SiteLink navigates in place when it names another docs page. */
export function Anchor({ href = '', children }: ComponentProps<'a'>) {
  return (
    <SiteLink href={href} className={LINK}>
      {children}
    </SiteLink>
  )
}

export function H2({ id, children, 'data-source-line': line }: ComponentProps<'h2'> & SourceLine) {
  return (
    <h2
      id={id}
      data-source-line={line}
      className="border-t border-line-1 pt-7 text-[clamp(28.5px,3vw,35px)] leading-[1.1] font-bold tracking-[-0.03em] text-title"
    >
      {children}
    </h2>
  )
}

export function H3({ id, children, 'data-source-line': line }: ComponentProps<'h3'> & SourceLine) {
  return (
    <h3 id={id} data-source-line={line} className="text-[23px] leading-[1.25] font-semibold tracking-[-0.02em] text-cream">
      {children}
    </h3>
  )
}

export function H4({ id, children, 'data-source-line': line }: ComponentProps<'h4'> & SourceLine) {
  return (
    <h4 id={id} data-source-line={line} className="text-[18.5px] leading-[1.3] font-semibold text-cream">
      {children}
    </h4>
  )
}

export function Quote({ children, 'data-source-line': line }: ComponentProps<'blockquote'> & SourceLine) {
  return <blockquote data-source-line={line} className="border-l-2 border-coral py-1 pl-5 text-sand [&_p]:text-sand">{children}</blockquote>
}

export function Rule({ 'data-source-line': line }: SourceLine) {
  return <hr data-source-line={line} className="border-line-1" />
}

const LIST = 'grid gap-2 pl-6 text-[18px] leading-[1.7] text-tan marker:text-coral [&_ol]:mt-2 [&_ul]:mt-2'

export function Ul({ children, 'data-source-line': line }: ComponentProps<'ul'> & SourceLine) {
  return <ul data-source-line={line} className={`list-disc ${LIST}`}>{children}</ul>
}

export function Ol({ children, start, 'data-source-line': line }: ComponentProps<'ol'> & SourceLine) {
  return (
    <ol start={start} data-source-line={line} className={`list-decimal ${LIST} marker:font-mono marker:text-[15.5px] marker:font-semibold`}>
      {children}
    </ol>
  )
}

export function Li({ children, 'data-source-line': line }: ComponentProps<'li'> & SourceLine) {
  return <li data-source-line={line} className="pl-1 [&>p+p]:mt-3">{children}</li>
}

/**
 * A table. One whose header row is empty, such as a list of requirements
 * written as label and value, is drawn without the header.
 */
export function Table({ children, 'data-source-line': line }: ComponentProps<'table'> & SourceLine) {
  return (
    <div data-source-line={line} className="overflow-x-auto">
      <table className="w-full border-collapse border-t border-line-3 text-left [&_thead:not(:has(th:not(:empty)))]:hidden">
        {children}
      </table>
    </div>
  )
}

export function Th({ children, style }: ComponentProps<'th'>) {
  return (
    <th
      style={style}
      className="border-b border-line-3 py-3 pr-4 align-bottom font-mono text-[12.5px] leading-[1.4] font-semibold tracking-widest whitespace-nowrap text-taupe uppercase"
    >
      {children}
    </th>
  )
}

export function Td({ children, style }: ComponentProps<'td'>) {
  return (
    <td
      style={style}
      className="border-b border-line-1 py-3 pr-4 align-top text-[17px] leading-[1.55] text-tan [&_strong]:font-mono [&_strong]:text-[13px] [&_strong]:tracking-widest [&_strong]:text-taupe [&_strong]:uppercase"
    >
      {children}
    </td>
  )
}

interface CodeFrameProps {
  label: string
  aside?: ReactNode
  children: ReactNode
  line?: SourceLine['data-source-line']
}

function CodeFrame({ label, aside, children, line }: CodeFrameProps) {
  return (
    <div data-source-line={line} className="overflow-hidden rounded-[17px] border border-line-3 bg-pane shadow-pane light:border-pane-edge">
      <div className="flex h-9 items-center justify-between gap-3 border-b border-line-2 bg-pane-head light:border-pane-edge pr-[6.5px] pl-[15.5px]">
        <span className="truncate font-mono text-[12px] leading-none font-bold tracking-[.12em] text-sand uppercase">
          {label}
        </span>
        {aside}
      </div>
      {children}
    </div>
  )
}

/** Shell commands, one per line, with a button that copies them. Lines starting `#` are comments. */
function Terminal({ source, line }: { source: string; line?: SourceLine['data-source-line'] }) {
  const lines = source.replace(/\s+$/, '').split('\n')
  const commands = lines.filter((line) => !line.startsWith('#'))
  const { copied, copy } = useCopy(commands.join('\n'))

  return (
    <CodeFrame
      label="Terminal"
      line={line}
      aside={
        <button
          type="button"
          onClick={copy}
          aria-label="Copy the commands"
          className={`h-[28.5px] cursor-pointer rounded-[4.5px] px-2.5 font-mono text-[12.5px] leading-none font-medium hover:bg-surface-3 ${
            copied ? 'text-coral' : 'text-taupe'
          }`}
        >
          {copied ? 'Copied' : 'Copy'}
        </button>
      }
    >
      <div className="overflow-x-auto p-4 font-mono text-[15.5px] leading-[1.6] whitespace-pre text-sand">
        {lines.map((line, i) =>
          line.startsWith('#') ? (
            <div key={i} className="text-code-comment">
              {line}
            </div>
          ) : (
            <div key={i}>
              <span className="mr-2.5 text-coral select-none">$</span>
              {line}
            </div>
          ),
        )}
      </div>
    </CodeFrame>
  )
}

/** Each fence language the docs write: how to highlight it, and the name its frame shows. */
const FENCES: Partial<Record<string, { language: Language; name: string }>> = {
  php: { language: 'php', name: 'PHP' },
  blade: { language: 'php', name: 'Blade' },
  warrant: { language: 'rule', name: 'Rules' },
  sql: { language: 'sql', name: 'SQL' },
}

const SHELLS = new Set(['bash', 'sh', 'shell', 'zsh'])

interface CodeBlockProps extends SourceLine {
  language?: string
  /** The rest of the fence's opening line. `title="path"` names the file the code belongs in. */
  meta?: string
  source?: string
}

/** A fenced code block, as `remarkDocs` hands it over. */
export function CodeBlock({ language = '', meta = '', source = '', 'data-source-line': line }: CodeBlockProps) {
  if (SHELLS.has(language)) return <Terminal source={source} line={line} />

  const fence = FENCES[language] ?? { language: 'plain', name: language || 'Text' }
  const file = /title="([^"]*)"/.exec(meta)?.[1]

  return (
    <CodeFrame
      label={file ?? fence.name}
      line={line}
      aside={file && <span className="pr-2 font-mono text-[13px] leading-none text-umber">{fence.name.toLowerCase()}</span>}
    >
      <CodeLines source={source} language={fence.language} gutter={48} className="py-3.5 text-[15px] leading-[1.7]" />
    </CodeFrame>
  )
}

const CALLOUTS = {
  note: { frame: 'border-line-4 bg-surface', title: 'text-sand' },
  tip: { frame: 'border-line-5 bg-surface', title: 'text-gold' },
  caution: { frame: 'border-coral-deep bg-coral/6', title: 'text-coral-soft' },
  danger: { frame: 'border-coral bg-coral/12', title: 'text-coral' },
} as const

function isCalloutKind(kind: string): kind is keyof typeof CALLOUTS {
  return kind in CALLOUTS
}

interface CalloutProps extends SourceLine {
  kind?: string
  title?: string
  children?: ReactNode
}

/** A `:::kind[Title]` block, as `remarkDocs` hands it over. */
export function Callout({ kind = 'note', title, children, 'data-source-line': line }: CalloutProps) {
  const style = isCalloutKind(kind) ? CALLOUTS[kind] : CALLOUTS.note

  return (
    <div role="note" data-source-line={line} className={`rounded-[9px] border px-5 py-[20px] ${style.frame}`}>
      <div className={`font-mono text-[12px] leading-none font-semibold tracking-[.12em] uppercase ${style.title}`}>
        {title ?? kind}
      </div>
      <div className="mt-3 grid gap-3 [&_p]:text-[17px] [&_p]:leading-[1.65] [&_p]:text-sand">{children}</div>
    </div>
  )
}
