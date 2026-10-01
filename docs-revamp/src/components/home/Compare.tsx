import { links } from '../../lib/links'
import { Eyebrow, SectionLink } from './SectionHeading'
import { CORNER_GLOW, SECTION_INNER, themed } from './sectionStyles'

const BACKGROUND = [CORNER_GLOW, `linear-gradient(180deg, ${themed('surface-2')} 0%, ${themed('ink-raised')} 100%)`].join(',')

const OTHERS = [
  {
    name: 'Typical role / permission systems',
    summary: 'Capabilities attached to the user through roles or permissions.',
    flow: ['USER', 'ROLE', 'PERMISSION'],
    question: (
      <>
        “Does this user have the <span className="whitespace-nowrap">edit-posts</span> permission?”
      </>
    ),
  },
  {
    name: 'Laravel Policies',
    summary: 'Excellent at authorizing one particular model instance.',
    flow: ['USER + MODEL', 'POLICY METHOD', 'BOOLEAN'],
    question: '“Can this user edit this specific post?”',
  },
]

const ROW = 'grid-switch-836 grid items-start gap-x-[clamp(31px,3.74vw,53px)] gap-y-6'
const LABEL = 'font-mono text-[12px] leading-none font-semibold tracking-[.12em] text-umber'

export function Compare() {
  return (
    <section className="border-b border-line-1" style={{ background: BACKGROUND }}>
      <div className={SECTION_INNER}>
        <div className="flex flex-wrap items-end justify-between gap-x-16 gap-y-6">
          <div className="max-w-215">
            <Eyebrow>02 — Mental models</Eyebrow>
            <h2 className="mt-5.5 text-[clamp(42px,5.28vw,75px)] leading-[0.97] font-extrabold tracking-[-0.045em] text-balance text-title">
              Authorization isn't just a yes/no question.
            </h2>
          </div>
          <SectionLink href={links.vsSpatie}>vs. Spatie Permission →</SectionLink>
        </div>

        <div className="mt-14 border-t border-line-5">
          {OTHERS.map((model, i) => (
            <div key={model.name} className={`${ROW} border-b border-line-5 py-[clamp(31px,3.74vw,48px)]`}>
              <div className="min-w-0">
                <div className="font-mono text-xs leading-none font-semibold tracking-[.12em] text-umber">
                  {String(i + 1).padStart(2, '0')}
                </div>
                <div className="mt-3.5 text-[clamp(24px,2.2vw,31px)] leading-[1.15] font-bold tracking-[-0.025em] text-cream">
                  {model.name}
                </div>
                <p className="mt-2.5 max-w-85 text-[17px] leading-[1.55] text-pretty text-tan">{model.summary}</p>
              </div>
              <div className="min-w-0">
                <div className={LABEL}>MENTAL MODEL</div>
                <Flow steps={model.flow} />
              </div>
              <div className="min-w-0">
                <div className={LABEL}>PRIMARY QUESTION</div>
                <div className="mt-3.5 text-[clamp(20px,1.65vw,23px)] leading-[1.35] font-semibold tracking-[-0.01em] text-pretty text-cream">
                  {model.question}
                </div>
              </div>
            </div>
          ))}

          <div
            className={`${ROW} -mt-px border-t-2 border-b border-t-coral border-b-line-5 py-[clamp(40px,4.84vw,66px)]`}
          >
            <div className="min-w-0">
              <div className="font-mono text-xs leading-none font-semibold tracking-[.12em] text-coral">03</div>
              <div className="mt-3.5 text-[clamp(44px,4.4vw,62px)] leading-[0.95] font-extrabold tracking-[-0.045em] text-coral">
                Warrant
              </div>
              <p className="mt-2.5 max-w-85 text-[17px] leading-[1.55] text-pretty text-tan">
                Rules compile to a SQL expression the database evaluates for every row.{' '}
                <span className="mt-1.5 block font-semibold text-coral">Authorization you can query.</span>
              </p>
            </div>
            <div className="min-w-0">
              <div className={LABEL}>MENTAL MODEL</div>
              <Flow steps={['USER + RULES + ROW', 'SQL EXPRESSION']} emphasiseLast />
            </div>
            <div className="min-w-0">
              <div className={LABEL}>PRIMARY QUESTION</div>
              <div className="mt-3.5 text-[clamp(26px,2.64vw,37px)] leading-[1.15] font-bold tracking-[-0.025em] text-pretty text-coral">
                “Which posts can this user edit?”
              </div>
              <div className="mt-6">
                <div className={LABEL}>ALSO ANSWERS</div>
                <div className="mt-3 grid gap-2 text-[17px] leading-[1.45] text-tan">
                  <div>“Can this user edit this specific post?”</div>
                  <div>“What can this user do with each row?”</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  )
}

function Flow({ steps, emphasiseLast = false }: { steps: readonly string[]; emphasiseLast?: boolean }) {
  return (
    <div className="mt-3.5 flex flex-wrap items-center gap-2 font-mono text-[14px] leading-none font-semibold tracking-[.06em]">
      {steps.map((step, i) => {
        const last = i === steps.length - 1
        return (
          <span key={step} className="contents">
            <span
              className={`rounded-sm border px-2.5 py-2 whitespace-nowrap ${
                emphasiseLast && last ? 'border-coral bg-coral/8 text-coral' : 'border-line-5 text-sand'
              }`}
            >
              {step}
            </span>
            {!last && <span className="text-umber">→</span>}
          </span>
        )
      })}
    </div>
  )
}
