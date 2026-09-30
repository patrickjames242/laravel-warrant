import type { ReactNode } from 'react'
import { useState } from 'react'
import { links } from '../../lib/links'
import { useElementWidth } from '../../lib/useElementWidth'
import { CodeLines } from '../CodeLines'
import { SiteLink } from '../SiteLink'
import { bend } from './flow'
import { FlowSegment } from './FlowSegment'
import type { Document, HeroQuestion } from './heroData'
import { DOCUMENTS, HERO_MODES, HERO_QUESTIONS, HERO_RULE, HERO_RULE_HIGHLIGHT } from './heroData'
import { themed, tint } from './sectionStyles'

type OutputView = 'sql' | 'rows'

const BADGES = [
  { logo: 'laravel', label: 'Laravel 11 · 12 · 13' },
  { logo: 'php', label: 'PHP 8.2+' },
  { logo: 'postgresql', label: 'PostgreSQL' },
  { logo: 'mysql', label: 'MySQL / MariaDB' },
  { logo: 'sqlite', label: 'SQLite' },
  { logo: 'mit', label: 'MIT licensed' },
]

const HERO_BACKGROUND = [
  `radial-gradient(ellipse 42% 38% at 0% 0%, ${tint('glow', 13)}, transparent 70%)`,
  `radial-gradient(ellipse 38% 30% at 12% 6%, ${tint('glow', 7.5)}, transparent 70%)`,
  `radial-gradient(ellipse 30% 36% at 92% 78%, ${tint('ember', 11)}, transparent 72%)`,
  `linear-gradient(180deg, ${themed('surface-2')} 0%, ${themed('ink')} 100%)`,
].join(',')

const GRID_BACKGROUND = `linear-gradient(${themed('line-0')} 1px, transparent 1px), linear-gradient(90deg, ${themed('line-0')} 1px, transparent 1px)`

const ROW_COLUMNS = 'grid-cols-[44px_minmax(143px,1.4fr)_79px_79px_64px_minmax(154px,1.2fr)]'

/** Width of the gap between the three question tabs, in pixels. */
const TAB_GAP = 17.5
/** Height of the connector between two panels that changes column on the way. */
const ELBOW_HEIGHT = 70
/** Height of the straight connector from the code panel to the output. */
const DROP_HEIGHT = 44

export function Hero() {
  const [question, setQuestion] = useState<HeroQuestion>('filter')
  const [view, setView] = useState<OutputView>('sql')

  const pickQuestion = (next: HeroQuestion) => {
    if (next === question) return
    setQuestion(next)
    setView(next === 'abilities' ? 'rows' : 'sql')
  }

  return (
    <section className="relative border-b border-line-1" style={{ background: HERO_BACKGROUND }}>
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-0 bg-size-[62px_62px] mask-[linear-gradient(180deg,#000_0%,rgba(0,0,0,.6)_60%,transparent_100%)]"
        style={{ backgroundImage: GRID_BACKGROUND }}
      />

      <div className="relative mx-auto flex max-w-330 flex-wrap items-start justify-center gap-[clamp(44px,5.5vw,79px)] px-[clamp(22px,4.4vw,53px)] pb-[clamp(70px,8.8vw,114px)]">
        {/*
          At least a screen tall, less the sticky header, with its content centred
          in it, so the page opens on the pitch alone and the workspace waits below.
        */}
        <div
          className="flex min-h-[calc(100svh-var(--spacing)*16)] min-w-0 flex-[1_1_462px] flex-col justify-center py-[clamp(44px,5.5vw,70px)] text-center"
          style={{ maxWidth: 1276 }}
        >
          <div className="flex items-center justify-center gap-2.5 font-mono text-[11.5px] leading-none font-medium tracking-[.12em] text-coral uppercase sm:text-xs sm:tracking-[.14em]">
            <span className="size-2 bg-coral" />
            Row-level authorization for Laravel
          </div>

          <h1 className="mt-7 text-[clamp(44px,6.6vw,79px)] leading-[0.92] font-extrabold tracking-[-0.05em] wrap-break-word text-balance text-cream">
            <span className="block text-balance">Write authorization once.</span>
            <span className="block text-balance text-coral">Ask any question.</span>
          </h1>

          <p className="mx-auto mt-8 max-w-150 text-[clamp(18.5px,2.2vw,23px)] leading-[1.6] text-pretty text-tan">
            Warrant compiles your authorization rules to SQL. The same rule answers{' '}
            <span className="text-cream">can they update this document</span>,{' '}
            <span className="text-cream">which documents can they update</span>, and{' '}
            <span className="text-cream">what can they do with every row</span>. You write it once.
          </p>

          <div className="mt-10 flex flex-wrap justify-center gap-3">
            <SiteLink
              href={links.installation}
              className="inline-flex h-12.5 items-center gap-2.5 rounded-[7.5px] bg-coral-strong px-5.5 text-base leading-none font-bold whitespace-nowrap text-ink hover:bg-coral-hover hover:text-ink"
            >
              Get started <span className="font-mono">→</span>
            </SiteLink>
            <SiteLink
              href={links.whyWarrant}
              className="inline-flex h-12.5 items-center rounded-[7.5px] border border-line-5 px-5.5 text-base leading-none font-semibold whitespace-nowrap text-cream hover:border-taupe hover:text-cream"
            >
              Why Warrant?
            </SiteLink>
          </div>

          <div className="mx-auto mt-9 flex max-w-180 flex-wrap justify-center gap-2 font-mono text-[14px] leading-none font-medium">
            {BADGES.map((badge) => (
              <span
                key={badge.logo}
                className="flex h-8 items-center gap-2 rounded-md border border-line-3 bg-ink/50 px-3 whitespace-nowrap text-sand"
              >
                <img src={`/logos/${badge.logo}.svg`} alt="" width={15} height={15} className="block flex-none" />
                {badge.label}
              </span>
            ))}
          </div>

          <div className="mt-4 text-[14.5px] leading-normal text-taupe">
            Warrant is in beta. Expect API changes between releases —{' '}
            <a href={links.issues} className="text-sand underline underline-offset-3">
              report an issue
            </a>
            .
          </div>
        </div>

        <Workspace question={question} onQuestion={pickQuestion} view={view} onView={setView} />
      </div>
    </section>
  )
}

interface WorkspaceProps {
  question: HeroQuestion
  onQuestion: (question: HeroQuestion) => void
  view: OutputView
  onView: (view: OutputView) => void
}

/**
 * The rule set, then one of three questions asked of it, then the code that
 * asks it, then what the database sends back. Dotted connectors trace the path
 * from the rule through the chosen question down to the output.
 */
function Workspace({ question, onQuestion, view, onView }: WorkspaceProps) {
  const mode = HERO_MODES[question]
  const selected = HERO_QUESTIONS.indexOf(question)
  const [fanRef, width] = useElementWidth<HTMLDivElement>(1000)

  const columnWidth = (width - TAB_GAP * 2) / 3
  const tabCentres = [0, 1, 2].map((i) => columnWidth * (i + 0.5) + TAB_GAP * i)
  const middle = width / 2
  const selectedCentre = tabCentres[selected] ?? middle

  return (
    <div className="mx-auto max-w-260 min-w-0 flex-[1_1_100%]">
      <div data-choreo="rules" className="rounded-xl border border-line-4 bg-surface shadow-panel">
        <div className="flex items-center gap-2.5 border-b border-line-2 px-4 py-3 font-mono text-xs leading-none font-medium text-sand">
          <span className="size-1.75 rounded-full bg-coral" />
          DOCUMENT RULES
        </div>
        <CodeLines
          source={HERO_RULE}
          language="rule"
          gutter={53}
          highlighted={HERO_RULE_HIGHLIGHT}
          className="pt-3.5 pb-1.5 text-[clamp(15.5px,1.38vw,17.5px)] leading-[1.75]"
        />
      </div>

      <div ref={fanRef} data-choreo="fan" aria-hidden="true" className="relative" style={{ height: ELBOW_HEIGHT }}>
        <Connector height={ELBOW_HEIGHT}>
          {/*
            Keyed by the tab each branch leads to, not by where it lands, so a
            change of width reshapes the same branches rather than replacing them.
          */}
          {tabCentres.map((x, i) =>
            i === selected ? null : (
              <path
                key={HERO_QUESTIONS[i]}
                data-choreo="branch"
                d={elbow(middle, x, ELBOW_HEIGHT)}
                className="fill-none stroke-line-5 stroke-1"
              />
            ),
          )}
          <FlowSegment d={elbow(middle, selectedCentre, ELBOW_HEIGHT)} />
        </Connector>
      </div>

      <div role="tablist" aria-label="Authorization question" className="grid grid-cols-3" style={{ gap: TAB_GAP }}>
        {HERO_QUESTIONS.map((key) => {
          const on = key === question
          return (
            <button
              key={key}
              type="button"
              role="tab"
              data-choreo="tab"
              aria-selected={on}
              onClick={() => {
                onQuestion(key)
              }}
              className={`min-w-0 cursor-pointer rounded-[7.5px] border px-3.5 py-3 text-left transition-colors duration-300 ${
                on ? 'border-coral bg-coral/8' : 'border-line-3 bg-transparent'
              }`}
            >
              <div
                className={`font-mono text-[12.5px] leading-none font-bold tracking-[.12em] ${on ? 'text-coral' : 'text-taupe'}`}
              >
                {HERO_MODES[key].label}
              </div>
              <div
                className={`mt-1.75 truncate text-[15px] leading-[1.25] font-medium ${on ? 'text-cream' : 'text-taupe'}`}
              >
                {HERO_MODES[key].question}
              </div>
            </button>
          )
        })}
      </div>

      <div data-choreo="to-code" aria-hidden="true" className="relative" style={{ height: ELBOW_HEIGHT }}>
        <Connector height={ELBOW_HEIGHT}>
          <FlowSegment d={elbow(selectedCentre, middle, ELBOW_HEIGHT)} />
        </Connector>
        <Dot />
      </div>

      <div data-choreo="code" className="overflow-hidden rounded-lg border border-line-2 bg-surface-2">
        <PanelHeading title="YOUR CODE" aside="php" />
        <CodeLines source={mode.code} language="php" gutter={44} className="py-3 text-[15px] leading-[1.7]" />
      </div>

      <div data-choreo="to-output" aria-hidden="true" className="relative" style={{ height: DROP_HEIGHT }}>
        <Connector height={DROP_HEIGHT}>
          <FlowSegment d={elbow(middle, middle, DROP_HEIGHT)} />
        </Connector>
        <Dot />
      </div>

      <div data-flow-start data-choreo="output" className="overflow-hidden rounded-lg border border-line-2 bg-surface-2">
        <div className="grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-center gap-3 border-b border-line-2 bg-surface px-3.5 py-1.5">
          <span className="font-mono text-[12px] leading-[1.3] font-bold tracking-[.12em] text-sand">
            RULES, COMPILED TO SQL
          </span>
          <div role="tablist" aria-label="Output" className="flex gap-1">
            {(['sql', 'rows'] as const).map((key) => {
              const on = key === view
              return (
                <button
                  key={key}
                  type="button"
                  role="tab"
                  aria-selected={on}
                  onClick={() => {
                    onView(key)
                  }}
                  className={`h-7 cursor-pointer rounded-[5.5px] border px-3 font-mono text-[12.5px] leading-none font-semibold tracking-[.1em] transition-all duration-250 ${
                    on ? 'border-coral bg-coral/10 text-coral' : 'border-line-3 bg-transparent text-taupe'
                  }`}
                >
                  {key.toUpperCase()}
                </button>
              )
            })}
          </div>
          <span className="justify-self-end text-right font-mono text-xs leading-[1.3] text-umber">
            {view === 'sql' ? 'generated · simplified' : mode.rowsNote}
          </span>
        </div>

        {view === 'sql' ? (
          <CodeLines
            source={mode.sql}
            language="sql"
            gutter={44}
            highlighted={mode.sqlHighlight}
            className="min-h-58 bg-ink py-3.5 text-[15px] leading-[1.75]"
          />
        ) : (
          <ResultRows question={question} />
        )}
      </div>

    </div>
  )
}

function ResultRows({ question }: { question: HeroQuestion }) {
  const abilities = question === 'abilities'
  const resultCell = abilities ? 'bg-coral/12 shadow-[inset_2px_0_0_var(--color-coral)]' : ''

  return (
    <div className="min-h-58 overflow-x-auto overflow-y-hidden">
      <div className="min-w-140">
        <div
          className={`grid ${ROW_COLUMNS} h-8 items-center bg-surface px-3.5 font-mono text-[11.5px] leading-none font-semibold tracking-[.1em] text-umber uppercase`}
        >
          <span>id</span>
          <span>title</span>
          <span>owner</span>
          <span>team</span>
          <span>locked</span>
          <span
            className={`-mr-3.5 flex items-center gap-2 self-stretch px-3 transition-all duration-400 ${resultCell} ${
              abilities ? 'text-coral' : ''
            }`}
          >
            {HERO_MODES[question].resultHeading}
          </span>
        </div>

        {DOCUMENTS.map((document) => {
          const row = describeRow(document, question)
          return (
            <div
              key={document.id}
              className={`grid ${ROW_COLUMNS} h-9.5 items-center border-t border-line-1 px-3.5 font-mono text-[14.5px] leading-none transition-[opacity,background,box-shadow] duration-400 ${
                abilities ? 'text-umber' : 'text-sand'
              } ${row.emphasis ? 'bg-coral/10 shadow-[inset_2px_0_0_var(--color-coral)]' : ''}`}
              style={{ opacity: row.opacity }}
            >
              <span className="text-taupe">#{document.id}</span>
              <span
                className={`font-sans text-sm transition-colors duration-400 ${abilities ? 'text-fawn' : 'text-cream'} ${
                  row.struck ? 'line-through' : ''
                }`}
              >
                {document.title}
              </span>
              <span>{document.owner}</span>
              <span>{document.team}</span>
              <span className={document.locked ? 'text-coral-soft' : 'text-umber'}>
                {document.locked ? 'yes' : 'no'}
              </span>
              <span
                className={`-mr-3.5 flex items-center self-stretch px-3 font-semibold transition-all duration-400 ${resultCell} ${row.resultClass}`}
              >
                {row.result}
              </span>
            </div>
          )
        })}
      </div>
    </div>
  )
}

interface RowDescription {
  result: string
  resultClass: string
  emphasis: boolean
  opacity: number
  struck: boolean
}

function describeRow(document: Document, question: HeroQuestion): RowDescription {
  const row: RowDescription = { result: '', resultClass: 'text-taupe', emphasis: false, opacity: 1, struck: false }

  switch (question) {
    case 'check':
      if (document.id === 2) return { ...row, result: 'true · manages_team', resultClass: 'text-coral', emphasis: true }
      if (document.id === 4) return { ...row, result: '403 · locked', resultClass: 'text-coral-soft' }
      return { ...row, opacity: 0.32 }

    case 'filter':
      if (document.abilities.includes('update')) {
        return { ...row, result: '✓ returned', resultClass: 'text-coral', emphasis: true }
      }
      return { ...row, result: '— filtered out', opacity: 0.28, struck: true }

    case 'abilities':
      return {
        ...row,
        result: JSON.stringify(document.abilities),
        resultClass: document.abilities.length ? 'text-blush' : 'text-taupe',
      }
  }
}

function PanelHeading({ title, aside }: { title: string; aside: string }) {
  return (
    <div className="flex h-9 items-center justify-between gap-3 border-b border-line-2 bg-surface px-3.5">
      <span className="font-mono text-[12px] leading-none font-bold tracking-[.12em] text-sand">{title}</span>
      <span className="font-mono text-xs leading-none text-umber">{aside}</span>
    </div>
  )
}

function Connector({ height, children }: { height: number; children: ReactNode }) {
  return (
    <svg width="100%" height={height} className="absolute top-0 left-0 overflow-visible">
      {children}
    </svg>
  )
}

/** A dot centred on the bottom edge of its connector, where the next panel begins. */
function Dot() {
  return (
    <div
      data-choreo="dot"
      className="absolute top-[calc(100%-var(--spacing))] left-1/2 z-1 -ml-1 size-2 rounded-full bg-coral"
    />
  )
}

/** A connector from `from` at its top to `to` at its bottom, turning across at half height. */
function elbow(from: number, to: number, height: number): string {
  return bend({ x: from, y: 0 }, { x: to, y: height }, height / 2)
}
