import { useGSAP } from '@gsap/react'
import gsap from 'gsap'
import type { PointerEvent as ReactPointerEvent, ReactNode } from 'react'
import { useLayoutEffect, useRef, useState } from 'react'
import { links } from '../../lib/links'
import { useElementWidth } from '../../lib/useElementWidth'
import { CodeLines } from '../CodeLines'
import { PanelHeading } from './PanelHeading'
import { SiteLink } from '../SiteLink'
import { bend } from './flow'
import { FlowSegment, REVEAL_LENGTH } from './FlowSegment'
import { HeroPrompt } from './HeroPrompt'
import { WarrantForLaravel } from '../WarrantForLaravel'
import type { Document, HeroQuestion, User } from './heroData'
import { ASKED_DOCUMENT, DOCUMENTS, HERO_MODES, HERO_QUESTIONS, HERO_RULE, HERO_RULE_HIGHLIGHT, USERS } from './heroData'
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

const HERO_BACKGROUND = `linear-gradient(180deg, ${themed('surface-2')} 0%, ${themed('ink')} 100%)`

/** The hero's glows, drawn over the grid so it fades into them. */
const HERO_GLOW = [
  `radial-gradient(ellipse 70% 55% at 45% 32%, ${tint('glow', 9)}, transparent 75%)`,
  `radial-gradient(ellipse 30% 36% at 92% 78%, ${tint('ember', 11)}, transparent 72%)`,
].join(',')

const GRID_BACKGROUND = `linear-gradient(${themed('line-0')} 1px, transparent 1px), linear-gradient(90deg, ${themed('line-0')} 1px, transparent 1px)`

const ROW_COLUMNS = 'grid-cols-[44px_minmax(143px,1.4fr)_79px_79px_64px_minmax(154px,1.2fr)]'
const USER_COLUMNS = 'grid-cols-[44px_minmax(110px,1.1fr)_86px_92px_64px_minmax(170px,1.3fr)]'

const HEADING_ROW = 'h-8 items-center bg-pane-head px-3.5 font-mono text-[11.5px] leading-none font-semibold tracking-[.1em] text-umber uppercase'
const BODY_ROW = 'h-9.5 items-center border-t border-line-1 px-3.5 font-mono text-[14.5px] leading-none transition-[opacity,background,box-shadow] duration-400'
const EMPHASISED_ROW = 'bg-coral/10 shadow-[inset_2px_0_0_var(--color-coral)]'
const RESULT_CELL = '-mr-3.5 flex items-center self-stretch px-3 transition-all duration-400'

/** Width of the gap between the question tabs, in pixels. */
const TAB_GAP = 17.5
/**
 * Below this workspace width, in pixels, the question tabs keep a fixed width
 * of `SCROLLING_TAB_WIDTH` and scroll sideways in a row that runs to the edges
 * of the screen.
 */
const SCROLL_BELOW = 640
const SCROLLING_TAB_WIDTH = 214
/** Height of the connector between two panels that changes column on the way. */
const ELBOW_HEIGHT = 70
/** Height of the straight connector from the code panel to the output. */
const DROP_HEIGHT = 44

/**
 * How long, in seconds, the connectors take to redraw to a newly picked
 * question: each bending one, and the short straight drop into the output.
 */
const REDRAW = 0.32
const DROP_REDRAW = 0.22
/** How long, in seconds, the line into and out of the tabs takes to fade while a picked tab scrolls to the middle. */
const LINE_FADE = 0.2
/** How long, in seconds, the code and output panels take to settle to their new height. */
const RESIZE = 0.45

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
      <div aria-hidden="true" className="pointer-events-none absolute inset-0" style={{ background: HERO_GLOW }} />

      <div className="relative mx-auto flex max-w-330 flex-wrap items-start justify-center gap-[clamp(44px,5.5vw,79px)] px-(--gutter) pb-[clamp(70px,8.8vw,114px)] [--gutter:clamp(22px,4.4vw,53px)]">
        {/*
          At least a screen tall, less the sticky header, with its content centred
          in it, so the page opens on the pitch alone and the workspace waits below.
        */}
        <div
          className="flex min-h-[calc(100svh-var(--spacing)*18)] min-w-0 flex-[1_1_462px] flex-col justify-center py-[clamp(44px,5.5vw,70px)] text-center"
          style={{ maxWidth: 1276 }}
        >
          <p data-choreo="intro">
            <WarrantForLaravel className="text-[clamp(34px,4.6vw,56px)]" />
          </p>

          <h1 className="mt-6 text-[clamp(44px,6.6vw,79px)] leading-[0.92] font-extrabold tracking-[-0.05em] wrap-break-word text-balance text-title">
            <span data-choreo="intro" className="block text-balance">
              Write authorization once.
            </span>
            <span className="sr-only">Use it to filter queries, check a row, list abilities, and filter users.</span>
            <span data-choreo="intro" className="mt-[0.3em] flex justify-center">
              <HeroPrompt />
            </span>
          </h1>

          <p
            data-choreo="intro"
            className="mx-auto mt-8 max-w-150 text-[clamp(18.5px,2.2vw,23px)] leading-[1.6] text-pretty text-tan"
          >
            Warrant compiles your authorization rules to SQL, so{' '}
            <span className="text-cream">one rule answers every access question</span> your app asks.
          </p>

          <div data-choreo="intro" className="mt-10 flex flex-wrap justify-center gap-3">
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

          <div
            data-choreo="intro"
            className="mx-auto mt-9 flex max-w-180 flex-wrap justify-center gap-2 font-mono text-[14px] leading-none font-medium"
          >
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

          <div data-choreo="intro" className="mt-4 text-[14.5px] leading-normal text-taupe">
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

/** The on-screen heights of the code and output panels, in pixels. */
interface PanelHeights {
  code: number
  output: number
}

/**
 * The rule set, then one of four questions asked of it, then the code that
 * asks it, then what the database sends back. Dotted connectors trace the path
 * from the rule through the chosen question down to the output.
 *
 * On a narrow workspace the questions scroll sideways, and the connectors
 * follow the scroll so the line keeps meeting the picked question wherever it
 * sits. A branch to a question scrolled out of view runs off the edge of the
 * screen, towards it.
 *
 * Picking another question redraws the line from the rule down to it, into
 * the code and on into the output, while the panels ease to their new height and their new
 * content fades in. Switching between SQL and rows does the same for the
 * output alone.
 */
function Workspace({ question, onQuestion, view, onView }: WorkspaceProps) {
  const mode = HERO_MODES[question]
  const selected = HERO_QUESTIONS.indexOf(question)
  const [fanRef, width] = useElementWidth<HTMLDivElement>(1000)
  const toCodeRef = useRef<HTMLDivElement>(null)
  const toOutputRef = useRef<HTMLDivElement>(null)
  const codeRef = useRef<HTMLDivElement>(null)
  const outputRef = useRef<HTMLDivElement>(null)
  // The panels' heights as the reader last saw them, taken just before a change
  // re-renders them, so the change can grow or shrink them from there.
  const before = useRef<PanelHeights | null>(null)
  const shownQuestion = useRef(question)
  const tabsRef = useRef<HTMLDivElement>(null)
  const scrolling = width < SCROLL_BELOW
  const [tabScroll, setTabScroll] = useState<TabScroll>({ left: 0, visible: 1, total: 1 })

  useLayoutEffect(() => {
    const list = tabsRef.current
    if (!list) return
    const measure = () => {
      setTabScroll(scrollOf(list))
    }
    measure()
    const observer = new ResizeObserver(measure)
    observer.observe(list)
    return () => {
      observer.disconnect()
    }
  }, [])

  /** The coral line into the tabs and the one out of them. */
  const tabLines = () =>
    [fanRef.current, toCodeRef.current].flatMap((connector) =>
      Array.from(connector?.querySelectorAll('.flow-path') ?? []),
    )

  // Counts picks, so a pick still waiting on the row to scroll gives way to a later one.
  const picks = useRef(0)

  /**
   * Scrolls the tab row so question `i` sits in the middle of the screen, and
   * calls `then` as the row is about to arrive.
   */
  const bringIntoView = (i: number, then: () => void) => {
    const list = tabsRef.current
    const tab = list?.children[i]
    if (!list || !(tab instanceof HTMLElement)) {
      then()
      return
    }
    const furthest = list.scrollWidth - list.clientWidth
    const target = Math.min(furthest, Math.max(0, tab.offsetLeft - (list.clientWidth - tab.offsetWidth) / 2))
    list.scrollTo({
      left: target,
      behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
    })
    whenScrollNears(list, target, then)
  }

  /**
   * Picks question `i`. A scrolling row first brings it to the middle while the
   * line into and out of the tabs fades away, and the line starts to redraw
   * just before the row gets there.
   */
  const pick = (key: HeroQuestion, i: number) => {
    const ticket = ++picks.current
    const choose = () => {
      if (ticket !== picks.current) return
      remember()
      onQuestion(key)
    }
    if (!scrolling) {
      choose()
      return
    }
    gsap.to(tabLines(), { opacity: 0, duration: LINE_FADE, ease: 'power1.out', overwrite: true })
    bringIntoView(i, choose)
  }

  const remember = () => {
    const code = codeRef.current
    const output = outputRef.current
    if (code && output) before.current = { code: code.offsetHeight, output: output.offsetHeight }
  }

  useGSAP(
    () => {
      const from = before.current
      const code = codeRef.current
      const output = outputRef.current
      const fan = fanRef.current
      const toCode = toCodeRef.current
      const toOutput = toOutputRef.current
      before.current = null
      const redraw = shownQuestion.current !== question
      shownQuestion.current = question
      if (!from || !code || !output || !fan || !toCode || !toOutput) return
      // The lines a pick faded out while the row scrolled show again, hidden
      // until their redraw reveals them.
      if (redraw) gsap.set(tabLines(), { clearProps: 'opacity', overwrite: true })
      if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return

      const settle = (panel: HTMLElement, height: number) =>
        gsap.fromTo(
          panel,
          { height },
          { height: 'auto', duration: RESIZE, ease: 'power2.inOut', overwrite: true, clearProps: 'height' },
        )
      const fadeIn = (content: Element | null) =>
        gsap.fromTo(
          content,
          { autoAlpha: 0, y: 6 },
          {
            autoAlpha: 1,
            y: 0,
            duration: 0.35,
            ease: 'power2.out',
            overwrite: true,
            clearProps: 'opacity,visibility,transform',
          },
        )
      const draw = (connector: HTMLElement, duration: number) =>
        gsap.fromTo(
          connector.querySelector('[data-flow-reveal]'),
          { strokeDashoffset: REVEAL_LENGTH },
          {
            strokeDashoffset: 0,
            duration,
            ease: 'power2.out',
            autoRound: false,
            overwrite: true,
            clearProps: 'strokeDashoffset',
          },
        )
      const land = (connector: HTMLElement) =>
        gsap.fromTo(
          connector.querySelector('[data-choreo="dot"]'),
          { scale: 0.6 },
          { scale: 1, duration: 0.4, ease: 'back.out(3)', overwrite: true, clearProps: 'scale' },
        )

      settle(output, from.output)
      if (!redraw) {
        fadeIn(output.lastElementChild)
        return
      }

      // The line runs again from the rule to the new question, into the code,
      // and on into the output, each panel's content arriving as it lands.
      settle(code, from.code)
      gsap
        .timeline()
        .add(draw(fan, REDRAW))
        .add(draw(toCode, REDRAW), '>-0.04')
        .add(land(toCode), '>-0.06')
        .add(fadeIn(code.lastElementChild), '<')
        .add(draw(toOutput, DROP_REDRAW), '<0.1')
        .add(land(toOutput), '>-0.06')
        .add(fadeIn(output.lastElementChild), '<')
    },
    { dependencies: [question, view], revertOnUpdate: false },
  )

  // A scrolling row's leading padding and the negative margin that takes it to
  // the screen's edge cancel out, so a tab's centre is the same sum either way,
  // less however far the row is scrolled.
  const count = HERO_QUESTIONS.length
  const tabWidth = scrolling ? SCROLLING_TAB_WIDTH : (width - TAB_GAP * (count - 1)) / count
  const scrolled = scrolling ? tabScroll.left : 0
  const tabCentres = HERO_QUESTIONS.map((_, i) => tabWidth * (i + 0.5) + TAB_GAP * i - scrolled)
  const middle = width / 2
  const selectedCentre = tabCentres[selected] ?? middle

  return (
    <div className="mx-auto max-w-260 min-w-0 flex-[1_1_100%]">
      <div className="overflow-hidden rounded-[17px] border border-line-4 bg-pane shadow-pane light:border-pane-edge">
        <div className="flex items-center gap-2.5 border-b border-line-2 light:border-pane-edge px-4 py-3 font-mono text-xs leading-none font-medium text-sand">
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

      <div ref={fanRef} aria-hidden="true" className="relative" style={{ height: ELBOW_HEIGHT }}>
        <Connector height={ELBOW_HEIGHT} clipped={scrolling}>
          {/*
            Keyed by the tab each branch leads to, not by where it lands, so a
            change of width reshapes the same branches rather than replacing them.
          */}
          {tabCentres.map((x, i) =>
            i === selected ? null : (
              <path
                key={HERO_QUESTIONS[i]}
                d={elbow(middle, x, ELBOW_HEIGHT)}
                className="fill-none stroke-line-5 stroke-1 light:stroke-line-3"
              />
            ),
          )}
          <FlowSegment d={elbow(middle, selectedCentre, ELBOW_HEIGHT)} />
        </Connector>
      </div>

      <div
        ref={tabsRef}
        role="tablist"
        aria-label="Authorization question"
        onScroll={(event) => {
          setTabScroll(scrollOf(event.currentTarget))
        }}
        className={`relative ${
          scrolling
            ? '-mx-(--gutter) flex snap-x snap-mandatory overflow-x-auto px-(--gutter) [scrollbar-width:none] [&::-webkit-scrollbar]:hidden'
            : 'grid grid-cols-4'
        }`}
        style={{ gap: TAB_GAP }}
      >
        {HERO_QUESTIONS.map((key, i) => {
          const on = key === question
          return (
            <button
              key={key}
              type="button"
              role="tab"
              aria-selected={on}
              onClick={() => {
                if (!on) pick(key, i)
              }}
              style={scrolling ? { width: SCROLLING_TAB_WIDTH } : undefined}
              className={`group min-w-0 flex-none snap-center cursor-pointer rounded-[17px] border px-3.5 py-3 text-left shadow-pane transition-colors duration-300 ${
                on
                  ? 'border-coral bg-coral/8'
                  : 'border-line-3 bg-pane hover:border-line-5 hover:bg-pane-head light:border-pane-edge light:hover:border-line-5'
              }`}
            >
              <div
                className={`font-mono text-[12.5px] leading-none font-bold tracking-[.12em] transition-colors duration-300 ${
                  on ? 'text-coral' : 'text-taupe group-hover:text-coral-soft'
                }`}
              >
                {HERO_MODES[key].label}
              </div>
              <div
                className={`mt-1.75 text-[15px] leading-[1.25] font-medium text-pretty transition-colors duration-300 ${
                  on ? 'text-cream' : 'text-taupe group-hover:text-sand'
                }`}
              >
                {HERO_MODES[key].question}
              </div>
            </button>
          )
        })}
      </div>

      <div
        ref={toCodeRef}
        aria-hidden="true"
        className="relative"
        style={{ height: ELBOW_HEIGHT }}
      >
        {scrolling && <ScrollTrack scroll={tabScroll} getRow={() => tabsRef.current} />}
        <Connector height={ELBOW_HEIGHT} clipped={scrolling}>
          <FlowSegment d={elbow(selectedCentre, middle, ELBOW_HEIGHT)} />
        </Connector>
        <Dot />
      </div>

      <div ref={codeRef} className="overflow-hidden rounded-[17px] border border-line-2 bg-pane shadow-pane light:border-pane-edge">
        <PanelHeading title="YOUR CODE" aside="php" />
        <CodeLines source={mode.code} language="php" gutter={44} className="py-3 text-[15px] leading-[1.7]" />
      </div>

      <div
        ref={toOutputRef}
        aria-hidden="true"
        className="relative"
        style={{ height: DROP_HEIGHT }}
      >
        <Connector height={DROP_HEIGHT}>
          <FlowSegment d={elbow(middle, middle, DROP_HEIGHT)} />
        </Connector>
        <Dot />
      </div>

      <div
        ref={outputRef}
        data-flow-start
        className="overflow-hidden rounded-[17px] border border-line-2 bg-pane shadow-pane light:border-pane-edge"
      >
        <div className="grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-center gap-3 border-b border-line-2 bg-pane-head px-3.5 py-1.5 light:border-pane-edge">
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
                    if (on) return
                    remember()
                    onView(key)
                  }}
                  className={`h-7 cursor-pointer rounded-[5.5px] border px-3 font-mono text-[12.5px] leading-none font-semibold tracking-[.1em] transition-all duration-250 ${
                    on
                      ? 'border-coral bg-coral/10 text-coral light:border-[1.5px]'
                      : 'border-line-3 bg-transparent text-taupe hover:border-line-5 hover:text-sand'
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
            className="min-h-58 py-3.5 text-[15px] leading-[1.75]"
          />
        ) : (
          <ResultRows question={question} />
        )}
      </div>
    </div>
  )
}

function ResultRows({ question }: { question: HeroQuestion }) {
  return question === 'users' ? <UserRows /> : <DocumentRows question={question} />
}

function DocumentRows({ question }: { question: DocumentQuestion }) {
  const abilities = question === 'abilities'
  const resultCell = abilities ? 'bg-coral/12 shadow-[inset_2px_0_0_var(--color-coral)]' : ''

  return (
    <div className="min-h-58 overflow-x-auto overflow-y-hidden">
      <div className="min-w-140">
        <div className={`grid ${ROW_COLUMNS} ${HEADING_ROW}`}>
          <span>id</span>
          <span>title</span>
          <span>owner</span>
          <span>team</span>
          <span>locked</span>
          <span className={`${RESULT_CELL} gap-2 ${resultCell} ${abilities ? 'text-coral' : ''}`}>
            {HERO_MODES[question].resultHeading}
          </span>
        </div>

        {DOCUMENTS.map((document) => {
          const row = describeRow(document, question)
          return (
            <div
              key={document.id}
              className={`grid ${ROW_COLUMNS} ${BODY_ROW} ${abilities ? 'text-umber' : 'text-sand'} ${
                row.emphasis ? EMPHASISED_ROW : ''
              }`}
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
              <span className={`${RESULT_CELL} font-semibold ${resultCell} ${row.resultClass}`}>{row.result}</span>
            </div>
          )
        })}
      </div>
    </div>
  )
}

/** The users table, each user kept or filtered out by whether they can update the asked document. */
function UserRows() {
  return (
    <div className="min-h-58 overflow-x-auto overflow-y-hidden">
      <div className="min-w-140">
        <div className={`grid ${USER_COLUMNS} ${HEADING_ROW}`}>
          <span>id</span>
          <span>name</span>
          <span>team</span>
          <span>manages</span>
          <span>admin</span>
          <span className={`${RESULT_CELL} normal-case`}>{HERO_MODES.users.resultHeading}</span>
        </div>

        {USERS.map((user) => {
          const row = describeUser(user, ASKED_DOCUMENT)
          return (
            <div
              key={user.id}
              className={`grid ${USER_COLUMNS} ${BODY_ROW} text-sand ${row.emphasis ? EMPHASISED_ROW : ''}`}
              style={{ opacity: row.opacity }}
            >
              <span className="text-taupe">#{user.id}</span>
              <span className={`font-sans text-sm text-cream ${row.struck ? 'line-through' : ''}`}>{user.name}</span>
              <span>{user.team}</span>
              <span className={user.manages ? '' : 'text-umber'}>{user.manages ?? '—'}</span>
              <span className={user.admin ? 'text-coral-soft' : 'text-umber'}>{user.admin ? 'yes' : 'no'}</span>
              <span className={`${RESULT_CELL} font-semibold ${row.resultClass}`}>{row.result}</span>
            </div>
          )
        })}
      </div>
    </div>
  )
}

type DocumentQuestion = Exclude<HeroQuestion, 'users'>

interface RowDescription {
  result: string
  resultClass: string
  emphasis: boolean
  opacity: number
  struck: boolean
}

const PLAIN_ROW: RowDescription = { result: '', resultClass: 'text-taupe', emphasis: false, opacity: 1, struck: false }
const RETURNED = { resultClass: 'text-coral', emphasis: true }
const FILTERED_OUT = { result: '— filtered out', opacity: 0.28, struck: true }

function describeRow(document: Document, question: DocumentQuestion): RowDescription {
  const row = PLAIN_ROW

  switch (question) {
    case 'check':
      if (document.id === ASKED_DOCUMENT.id) return { ...row, ...RETURNED, result: 'true · manages_team' }
      if (document.id === 4) return { ...row, result: '403 · locked', resultClass: 'text-coral-soft' }
      return { ...row, opacity: 0.32 }

    case 'filter':
      if (document.abilities.includes('update')) return { ...row, ...RETURNED, result: '✓ returned' }
      return { ...row, ...FILTERED_OUT }

    case 'abilities':
      return {
        ...row,
        result: JSON.stringify(document.abilities),
        resultClass: document.abilities.length ? 'text-blush' : 'text-taupe',
      }
  }
}

/** Whether `user` can update `document` under the hero's rule, and through which condition. */
function describeUser(user: User, document: Document): RowDescription {
  if (user.name === document.owner) return { ...PLAIN_ROW, ...RETURNED, result: '✓ owns_document' }
  if (user.manages === document.team) return { ...PLAIN_ROW, ...RETURNED, result: '✓ manages_team' }
  return { ...PLAIN_ROW, ...FILTERED_OUT }
}

/**
 * How close, in pixels, a smooth scroll must come to where it is headed to
 * count as arriving. A smooth scroll eases out, so its last few pixels take
 * the last split second of it.
 */
const ARRIVING_WITHIN = 12
/** How many animation frames a scroll must hold still for to count as stopped short of where it was headed. */
const STOPPED_FRAMES = 4
/** The longest, in milliseconds, to wait for a scroll to arrive before carrying on regardless. */
const ARRIVAL_LIMIT = 1500

/**
 * Calls `then` once `element` has scrolled to within `ARRIVING_WITHIN` of
 * `target`, or has stopped somewhere else, or has taken too long.
 */
function whenScrollNears(element: HTMLElement, target: number, then: () => void) {
  const started = performance.now()
  let last = element.scrollLeft
  let still = 0

  const check = () => {
    const now = element.scrollLeft
    still = now === last ? still + 1 : 0
    last = now
    const arriving = Math.abs(now - target) <= ARRIVING_WITHIN
    if (arriving || still >= STOPPED_FRAMES || performance.now() - started > ARRIVAL_LIMIT) then()
    else requestAnimationFrame(check)
  }
  requestAnimationFrame(check)
}

/** How far a row is scrolled, how much of it shows, and how wide it is in all, in pixels. */
interface TabScroll {
  left: number
  visible: number
  total: number
}

function scrollOf(element: HTMLElement): TabScroll {
  return { left: element.scrollLeft, visible: element.clientWidth, total: element.scrollWidth }
}

/**
 * A scrollbar for the tab row, drawn under it. A phone shows its own scrollbar
 * only while the row is moving, so this one stays to show there is more to
 * scroll to. Dragging the thumb scrolls the row with it, and pressing the
 * track elsewhere first centres the thumb there. The row's snapping is held
 * off while the thumb is dragged, so it follows the pointer smoothly, and
 * settles the row on a tab once the thumb is let go.
 */
interface ScrollTrackProps {
  scroll: TabScroll
  /** The row the track scrolls. */
  getRow: () => HTMLElement | null
}

function ScrollTrack({ scroll, getRow }: ScrollTrackProps) {
  const thumbWidth = scroll.visible / scroll.total

  const drag = (event: ReactPointerEvent<HTMLDivElement>) => {
    const list = getRow()
    if (!list) return
    const track = event.currentTarget
    const box = track.getBoundingClientRect()
    // Row pixels per track pixel.
    const ratio = scroll.total / box.width
    const thumbLeft = (scroll.left / scroll.total) * box.width
    const onThumb = event.clientX >= box.left + thumbLeft && event.clientX <= box.left + thumbLeft + thumbWidth * box.width
    const startScroll = onThumb ? list.scrollLeft : (event.clientX - box.left) * ratio - scroll.visible / 2
    const startX = event.clientX

    list.style.scrollSnapType = 'none'
    list.scrollLeft = startScroll
    track.setPointerCapture(event.pointerId)

    const move = (moved: PointerEvent) => {
      list.scrollLeft = startScroll + (moved.clientX - startX) * ratio
    }
    const release = () => {
      list.style.scrollSnapType = ''
      track.removeEventListener('pointermove', move)
      track.removeEventListener('pointerup', release)
      track.removeEventListener('pointercancel', release)
    }
    track.addEventListener('pointermove', move)
    track.addEventListener('pointerup', release)
    track.addEventListener('pointercancel', release)
  }

  return (
    <div
      aria-hidden="true"
      onPointerDown={drag}
      className="group absolute inset-x-0 top-0.5 z-2 h-6 cursor-pointer touch-none"
    >
      <div className="absolute inset-x-0 top-1/2 h-1 -translate-y-1/2 rounded-full bg-line-2 transition-[height] group-hover:h-1.5">
        <div
          className="absolute inset-y-0 rounded-full bg-taupe/60 group-hover:bg-taupe"
          style={{ left: `${(scroll.left / scroll.total) * 100}%`, width: `${thumbWidth * 100}%` }}
        />
      </div>
    </div>
  )
}

interface ConnectorProps {
  height: number
  /** Whether to cut the connector off at the edges of the screen, for lines that lead to a tab scrolled out of view. */
  clipped?: boolean
  children: ReactNode
}

function Connector({ height, clipped = false, children }: ConnectorProps) {
  return (
    <svg
      width="100%"
      height={height}
      className={`pointer-events-none absolute top-0 left-0 overflow-visible ${
        clipped ? '[clip-path:inset(-8px_calc(-1*var(--gutter)))]' : ''
      }`}
    >
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
