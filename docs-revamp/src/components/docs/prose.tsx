import type { ComponentProps, ReactNode } from 'react'
import { findPage } from '../../docs/nav'
import type { Language } from '../../lib/highlight'
import { useCopy } from '../../lib/useCopy'
import { CodeLines } from '../CodeLines'
import { DocLink } from './DocLink'

/*
 * What each element of a docs page's Markdown is drawn as. The page body
 * spaces its children out, so none of these carry an outer margin of their own.
 */

export function P({ children }: ComponentProps<'p'>) {
  return <p className="text-[18px] leading-[1.7] text-pretty text-tan">{children}</p>
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

/**
 * A link to another docs page is navigated in place, and lands on the heading
 * its fragment names. Anything else is an ordinary link.
 */
export function Anchor({ href = '', children }: ComponentProps<'a'>) {
  const match = /^\/([^#?]*?)\/?(?:#(.*))?$/.exec(href)
  const slug = match?.[1]

  if (slug !== undefined && findPage(slug)) {
    return (
      <DocLink slug={slug} hash={match?.[2]} className={LINK}>
        {children}
      </DocLink>
    )
  }

  return (
    <a href={href} className={LINK}>
      {children}
    </a>
  )
}

export function H2({ id, children }: ComponentProps<'h2'>) {
  return (
    <h2
      id={id}
      className="border-t border-line-1 pt-7 text-[clamp(28.5px,3vw,35px)] leading-[1.1] font-bold tracking-[-0.03em] text-cream"
    >
      {children}
    </h2>
  )
}

export function H3({ id, children }: ComponentProps<'h3'>) {
  return (
    <h3 id={id} className="text-[23px] leading-[1.25] font-semibold tracking-[-0.02em] text-cream">
      {children}
    </h3>
  )
}

export function H4({ id, children }: ComponentProps<'h4'>) {
  return (
    <h4 id={id} className="text-[18.5px] leading-[1.3] font-semibold text-cream">
      {children}
    </h4>
  )
}

export function Quote({ children }: ComponentProps<'blockquote'>) {
  return <blockquote className="border-l-2 border-coral py-1 pl-5 text-sand [&_p]:text-sand">{children}</blockquote>
}

export function Rule() {
  return <hr className="border-line-1" />
}

const LIST = 'grid gap-2 pl-6 text-[18px] leading-[1.7] text-tan marker:text-coral [&_ol]:mt-2 [&_ul]:mt-2'

export function Ul({ children }: ComponentProps<'ul'>) {
  return <ul className={`list-disc ${LIST}`}>{children}</ul>
}

export function Ol({ children, start }: ComponentProps<'ol'>) {
  return (
    <ol start={start} className={`list-decimal ${LIST} marker:font-mono marker:text-[15.5px] marker:font-semibold`}>
      {children}
    </ol>
  )
}

export function Li({ children }: ComponentProps<'li'>) {
  return <li className="pl-1 [&>p+p]:mt-3">{children}</li>
}

/**
 * A table. One whose header row is empty, such as a list of requirements
 * written as label and value, is drawn without the header.
 */
export function Table({ children }: ComponentProps<'table'>) {
  return (
    <div className="overflow-x-auto">
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

function CodeFrame({ label, aside, children }: { label: string; aside?: ReactNode; children: ReactNode }) {
  return (
    <div className="overflow-hidden rounded-[9px] border border-line-3 bg-surface-2">
      <div className="flex h-9 items-center justify-between gap-3 border-b border-line-2 bg-surface pr-[6.5px] pl-[15.5px]">
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
function Terminal({ source }: { source: string }) {
  const lines = source.replace(/\s+$/, '').split('\n')
  const commands = lines.filter((line) => !line.startsWith('#'))
  const { copied, copy } = useCopy(commands.join('\n'))

  return (
    <CodeFrame
      label="Terminal"
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

interface CodeBlockProps {
  language?: string
  /** The rest of the fence's opening line. `title="path"` names the file the code belongs in. */
  meta?: string
  source?: string
}

/** A fenced code block, as `remarkDocs` hands it over. */
export function CodeBlock({ language = '', meta = '', source = '' }: CodeBlockProps) {
  if (SHELLS.has(language)) return <Terminal source={source} />

  const fence = FENCES[language] ?? { language: 'plain', name: language || 'Text' }
  const file = /title="([^"]*)"/.exec(meta)?.[1]

  return (
    <CodeFrame
      label={file ?? fence.name}
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

interface CalloutProps {
  kind?: string
  title?: string
  children?: ReactNode
}

/** A `:::kind[Title]` block, as `remarkDocs` hands it over. */
export function Callout({ kind = 'note', title, children }: CalloutProps) {
  const style = isCalloutKind(kind) ? CALLOUTS[kind] : CALLOUTS.note

  return (
    <div role="note" className={`rounded-[9px] border px-5 py-[20px] ${style.frame}`}>
      <div className={`font-mono text-[12px] leading-none font-semibold tracking-[.12em] uppercase ${style.title}`}>
        {title ?? kind}
      </div>
      <div className="mt-3 grid gap-3 [&_p]:text-[17px] [&_p]:leading-[1.65] [&_p]:text-sand">{children}</div>
    </div>
  )
}
