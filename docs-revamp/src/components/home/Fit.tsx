import { Eyebrow } from './SectionHeading'
import { CORNER_GLOW, SECTION_INNER } from './sectionStyles'

const BACKGROUND = [
  CORNER_GLOW,
  'radial-gradient(ellipse 60% 45% at 10% 8%, rgba(255,100,62,0.06), transparent 70%)',
  'radial-gradient(ellipse 50% 50% at 95% 100%, rgba(140,68,36,0.06), transparent 70%)',
  '#140f0d',
].join(',')

const FITS = [
  'Permissions depend on properties of individual records.',
  'You need to filter database queries with the same logic you use for individual checks.',
  'Authorization logic is becoming duplicated between Policies and query scopes.',
  'Access varies by tenant, department, ownership, hierarchy or other relational data.',
  'Rules need to be stored or generated dynamically.',
  'Your UI needs abilities for many rows at once.',
]

const MISFITS = [
  'Your authorization is simple.',
  'Ordinary Laravel Policies already express everything cleanly.',
  'You only need basic role and permission checks.',
  'Your application never filters queries by what a user is allowed to see.',
]

export function Fit() {
  return (
    <section className="border-b border-line-1" style={{ background: BACKGROUND }}>
      <div className={SECTION_INNER}>
        <Eyebrow>03 — Fit</Eyebrow>
        <h2 className="mt-5.5 max-w-225 text-[clamp(44px,6.16vw,88px)] leading-[0.95] font-extrabold tracking-[-0.045em] text-balance text-cream">
          When should you use Warrant?
        </h2>
        <p className="mt-6 max-w-140 text-[18.5px] leading-[1.6] text-tan">
          Not always. It solves a specific problem, and plenty of applications never have it.
        </p>

        <div className="mt-14 grid grid-cols-[repeat(auto-fit,minmax(min(100%,462px),1fr))] gap-x-[clamp(44px,6.6vw,106px)] gap-y-12">
          <div>
            <ListHeading className="border-coral text-cream">Warrant makes sense when…</ListHeading>
            <div className="grid">
              {FITS.map((item) => (
                <ListItem key={item} mark="+" className="text-cream" markClassName="text-coral">
                  {item}
                </ListItem>
              ))}
            </div>
          </div>
          <div>
            <ListHeading className="border-line-5 text-tan">You may not need Warrant when…</ListHeading>
            <div className="grid">
              {MISFITS.map((item) => (
                <ListItem key={item} mark="–" className="text-tan" markClassName="text-umber">
                  {item}
                </ListItem>
              ))}
            </div>
            <p className="mt-6 text-[16.5px] leading-[1.6] text-taupe">
              Policies and role packages are good tools. Use them where they fit, and Warrant where authorization
              needs to reach the query.
            </p>
          </div>
        </div>
      </div>
    </section>
  )
}

function ListHeading({ className, children }: { className: string; children: string }) {
  return (
    <div
      className={`border-b-2 pb-4.5 text-[clamp(24px,2.2vw,31px)] leading-[1.2] font-bold tracking-[-0.02em] ${className}`}
    >
      {children}
    </div>
  )
}

interface ListItemProps {
  mark: string
  className: string
  markClassName: string
  children: string
}

function ListItem({ mark, className, markClassName, children }: ListItemProps) {
  return (
    <div className={`grid grid-cols-[31px_1fr] gap-2 border-b border-line-2 py-4 text-[18.5px] leading-normal ${className}`}>
      <span className={`font-mono text-[16.5px] leading-normal font-semibold ${markClassName}`}>{mark}</span>
      <span className="text-pretty">{children}</span>
    </div>
  )
}
