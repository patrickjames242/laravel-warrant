import { useLayoutEffect, useRef } from 'react'
import { links } from '../../lib/links'
import { CodeLines } from '../CodeLines'
import { layoutBox } from './flow'
import { PanelHeading } from './PanelHeading'
import { Eyebrow, SectionLink } from './SectionHeading'
import { CORNER_GLOW, SECTION_INNER, themed, tint } from './sectionStyles'

const PIECES = [
  {
    title: 'Schema',
    tagline: 'What can be expressed.',
    body: "The vocabulary for one resource: its abilities and the conditions a rule may test. Each condition knows how to become SQL. It decides nothing.",
    label: 'DocumentSchema.php',
    language: 'php',
    source: `class DocumentSchema extends WarrantSchema
{
    public const model = Document::class;

    #[Ability] public const VIEW = 'view';
    #[Ability] public const APPROVE = 'approve';

    #[RowCondition]
    public function isOwner(RowConditionContext $c): Builder
    {
        return $c->query->where('user_id', $c->user->id);
    }
}`,
  },
  {
    title: 'Rules',
    tagline: 'What is allowed.',
    body: "The policy itself, as plain strings that use the schema's words. Warrant validates every rule against the schema when it compiles. Rules are data.",
    label: 'RULES',
    language: 'rule',
    source: `if is_owner
    they can view, update

if is_manager and same_department
    they can approve`,
  },
  {
    title: 'Resolver',
    tagline: 'Which rules apply.',
    body: 'One class you write. At request time it hands Warrant the rule set for this user and this resource, from wherever your app keeps them.',
    label: 'RuleResolver.php',
    language: 'php',
    source: `class RoleRuleResolver implements RuleResolver
{
    public function resolve(RuleResolutionContext $context): WarrantRuleSet
    {
        return WarrantRuleSet::fromSyntax(
            $context->user->role->rules,
            $context->schemaKey,
        );
    }
}`,
  },
] as const

const ENTRY_POINTS = [
  { label: 'LARAVEL GATE', code: '$user->can()' },
  { label: 'QUERY BUILDER', code: '->userHasAbility()' },
  { label: 'SQL', code: 'where ( … )' },
]

const BACKGROUND = [
  CORNER_GLOW,
  `radial-gradient(ellipse 50% 40% at 12% 0%, ${tint('glow', 3.5)}, transparent 70%)`,
  `linear-gradient(180deg, ${themed('surface-2')} 0%, ${themed('ink-raised')} 100%)`,
].join(',')

/** Diameter of a timeline node, in pixels. */
const NODE = 44

export function MentalModel() {
  const timeline = useTimelineAlignment()

  return (
    <section className="border-b border-line-1" style={{ background: BACKGROUND }}>
      <div className={SECTION_INNER}>
        {/*
          Indented by the timeline's rail column and gap, so the path arriving from
          the hero runs down the rail's lane beside the heading, not through it.
        */}
        <div
          data-flow-heading
          data-choreo="heading"
          className="flex flex-wrap items-end justify-between gap-x-16 gap-y-6 pl-[calc(53px+clamp(17.5px,2.86vw,40px))]"
        >
          <div className="max-w-190">
            <Eyebrow>01 — The mental model</Eyebrow>
            <h2 className="mt-5.5 text-[clamp(44px,6.16vw,88px)] leading-[0.95] font-extrabold tracking-[-0.045em] text-balance text-title">
              Three pieces. Each does one job.
            </h2>
          </div>
          <SectionLink href={links.coreConcepts}>Core concepts →</SectionLink>
        </div>

        <div ref={timeline} className="mt-16 grid grid-cols-1">
          {PIECES.map((piece, i) => (
            <div
              key={piece.title}
              data-choreo="piece"
              className="grid grid-cols-[53px_minmax(0,1fr)] gap-x-[clamp(17.5px,2.86vw,40px)]"
            >
              <div aria-hidden="true" data-rail className="relative">
                <div
                  data-node
                  data-flow-end={i === 0 ? '' : undefined}
                  className="absolute top-0 left-1/2 -ml-5 flex size-10 items-center justify-center rounded-full border border-coral bg-surface-2 font-mono text-xs leading-none font-semibold tracking-[.06em] text-coral"
                >
                  {String(i + 1).padStart(2, '0')}
                </div>
                <div
                  data-line
                  className="dot-rail absolute left-1/2 -ml-0.5 w-1"
                  style={{ top: NODE, bottom: 0 }}
                />
              </div>

              <div className="flex min-w-0 flex-wrap items-center gap-x-[clamp(31px,4.4vw,62px)] gap-y-6 pb-[clamp(35px,4.4vw,57px)]">
                <div className="min-w-0 flex-[1_1_286px]">
                  <div
                    data-title
                    data-choreo="item"
                    className="text-[clamp(35px,3.3vw,48px)] leading-none font-bold tracking-[-0.035em] text-title"
                  >
                    {piece.title}
                  </div>
                  <div
                    data-choreo="item"
                    className="mt-2.5 text-[clamp(18.5px,1.54vw,22px)] leading-[1.3] font-medium text-taupe"
                  >
                    {piece.tagline}
                  </div>
                  <p data-choreo="item" className="mt-3.5 text-[17px] leading-[1.6] text-pretty text-tan">
                    {piece.body}
                  </p>
                </div>
                <div data-choreo="item" className="min-w-0 flex-[2_1_484px]">
                  <div className="overflow-hidden rounded-[17px] border border-line-2 bg-surface-2 shadow-pane light:border-pane-warm-edge light:bg-pane-warm">
                    <PanelHeading title={piece.label} aside={piece.language} tone="warm" />
                    <CodeLines
                      source={piece.source}
                      language={piece.language}
                      gutter={44}
                      className="py-3 text-[14.5px] leading-[1.7]"
                    />
                  </div>
                </div>
              </div>
            </div>
          ))}
        </div>

        <div aria-hidden="true" className="relative size-12">
          <div data-choreo="rail-final" className="dot-rail absolute inset-y-0 left-1/2 -ml-0.5 w-1" />
        </div>

        <div
          data-choreo="bar"
          className="flex flex-wrap items-center justify-between gap-x-8 gap-y-2.5 bg-coral-strong px-[clamp(22px,2.64vw,35px)] py-[clamp(22px,2.64vw,31px)] text-ink"
        >
          <div className="text-[clamp(37px,4.4vw,62px)] leading-none font-extrabold tracking-[-0.04em]">Warrant</div>
          <div className="max-w-140 font-mono text-sm leading-normal font-medium">
            validates rules against the schema → compiles one SQL predicate per ability
          </div>
        </div>

        <div data-choreo="entries" aria-hidden="true" className="grid-switch-858 grid h-10 auto-rows-[44px] overflow-hidden">
          {ENTRY_POINTS.map((entry) => (
            <div key={entry.label} className="flex justify-center">
              <div className="w-px bg-line-5" />
            </div>
          ))}
        </div>

        <div data-choreo="entries" className="grid-switch-858 grid border-y border-line-5">
          {ENTRY_POINTS.map((entry, i) => (
            <div
              key={entry.label}
              className={`px-5 py-5.5 text-center ${i < ENTRY_POINTS.length - 1 ? 'border-r border-line-3' : ''}`}
            >
              <div className="font-mono text-xs leading-none font-semibold tracking-[.12em] text-taupe">
                {entry.label}
              </div>
              <div className="mt-2.5 font-mono text-[16.5px] leading-[1.4] font-medium text-cream">{entry.code}</div>
            </div>
          ))}
        </div>
      </div>
    </section>
  )
}

/**
 * Centres each timeline node on its piece's title, and stretches the dotted
 * line below it from its border down to the next node's. Titles move as the
 * text and code beside them wrap, so the positions are measured rather than
 * fixed, and measured by layout, so a title still sliding into place is placed
 * by where it will come to rest.
 */
function useTimelineAlignment() {
  const ref = useRef<HTMLDivElement>(null)

  useLayoutEffect(() => {
    const container = ref.current
    if (!container) return

    const align = () => {
      const rails = [...container.querySelectorAll<HTMLElement>('[data-rail]')]
      const titles = [...container.querySelectorAll<HTMLElement>('[data-title]')]

      const tops = rails.map((rail, i) => {
        const title = titles[i]
        if (!title) return 0
        const railBox = layoutBox(rail)
        const titleBox = layoutBox(title)
        return Math.round(titleBox.top - railBox.top + titleBox.height / 2 - NODE / 2)
      })

      rails.forEach((rail, i) => {
        const node = rail.querySelector<HTMLElement>('[data-node]')
        const line = rail.querySelector<HTMLElement>('[data-line]')
        const top = tops[i] ?? 0
        const nextTop = tops[i + 1]
        if (node) node.style.top = `${top}px`
        if (line) {
          line.style.top = `${top + NODE}px`
          line.style.bottom = nextTop === undefined ? '0px' : `${-nextTop}px`
        }
      })
    }

    align()
    void document.fonts.ready.then(align)

    const observer = new ResizeObserver(align)
    for (const rail of container.querySelectorAll('[data-rail]')) {
      if (rail.parentElement) observer.observe(rail.parentElement)
    }
    return () => {
      observer.disconnect()
    }
  }, [])

  return ref
}
