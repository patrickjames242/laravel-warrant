import { INSTALL_COMMAND, links } from '../../lib/links'
import { useCopy } from '../../lib/useCopy'
import { SiteLink } from '../SiteLink'
import { CORNER_GLOW, themed, tint } from './sectionStyles'

const BACKGROUND = [
  CORNER_GLOW,
  `radial-gradient(ellipse 80% 90% at 18% 105%, ${tint('glow', 15)}, transparent 65%)`,
  `radial-gradient(ellipse 55% 60% at 92% 8%, ${tint('ember', 10)}, transparent 70%)`,
  `linear-gradient(180deg, ${themed('ink-raised')} 0%, ${themed('surface-2')} 100%)`,
].join(',')

export function CallToAction() {
  return (
    <section className="relative overflow-hidden" style={{ background: BACKGROUND }}>
      <div
        aria-hidden="true"
        className="pointer-events-none absolute -right-[2.2vw] -bottom-[4.4vw] font-mono text-[clamp(154px,28.6vw,462px)] leading-[0.8] font-extrabold tracking-[-0.08em] whitespace-nowrap text-transparent select-none [-webkit-text-stroke:1px_var(--color-line-3)]"
      >
        they can
      </div>

      <div className="relative mx-auto max-w-330 px-[clamp(22px,4.4vw,53px)] py-[clamp(88px,11vw,160px)]">
        <h2 className="max-w-220 text-[clamp(40px,5.5vw,88px)] leading-[0.92] font-extrabold tracking-[-0.055em] text-balance text-cream">
          Stop writing the same authorization rule{' '}
          <span className="relative inline-block text-coral">
            twice.
            <span aria-hidden="true" className="absolute top-[54%] -right-[2%] -left-[2%] h-[0.07em] bg-coral" />
          </span>
        </h2>

        <div className="mt-11 flex flex-wrap gap-3">
          <SiteLink
            href={links.quickStart}
            className="inline-flex h-14 items-center gap-2.5 rounded-[7.5px] bg-coral-strong px-6 text-[18.5px] leading-none font-bold whitespace-nowrap text-ink hover:bg-coral-hover hover:text-ink"
          >
            Build your first Warrant schema →
          </SiteLink>
          <SiteLink
            href={links.whyWarrant}
            className="inline-flex h-14 items-center rounded-[7.5px] border border-line-5 px-6 text-[18.5px] leading-none font-semibold whitespace-nowrap text-cream hover:border-taupe hover:text-cream"
          >
            Read Why Warrant →
          </SiteLink>
        </div>

        <InstallCommand />
      </div>
    </section>
  )
}

function InstallCommand() {
  const { copied, copy } = useCopy(INSTALL_COMMAND)

  return (
    <div className="mt-7 inline-flex max-w-full items-center overflow-hidden rounded-[17px] border border-line-3 bg-surface light:border-line-1">
      <code className="flex h-12 min-w-0 items-center overflow-x-auto overflow-y-hidden px-4.5 font-mono text-sm leading-none whitespace-nowrap text-sand">
        <span className="mr-2.5 text-coral">$</span>
        {INSTALL_COMMAND}
      </code>
      <button
        type="button"
        onClick={copy}
        aria-label="Copy install command"
        className={`h-12 flex-none cursor-pointer border-0 border-l border-line-3 bg-surface-3 light:border-line-1 px-4 font-mono text-[14px] leading-none font-medium hover:bg-line-2 ${
          copied ? 'text-coral' : 'text-sand'
        }`}
      >
        {copied ? 'Copied' : 'Copy'}
      </button>
    </div>
  )
}
