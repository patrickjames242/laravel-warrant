/** Each tone of code panel's title bar colours, the dark theme's first. */
const TONES = {
  plain: 'bg-pane-head light:border-pane-edge',
  warm: 'bg-pane-head light:border-pane-warm-edge light:bg-pane-warm-head',
} as const

export type PanelTone = keyof typeof TONES

/**
 * The title bar along the top of a code panel: what the code is, and its
 * language or a note. A `warm` panel is a deeper brown in the light theme, for
 * a section whose background is close to the plain panel's.
 */
export function PanelHeading({ title, aside, tone = 'plain' }: { title: string; aside: string; tone?: PanelTone }) {
  return (
    <div className={`flex h-9 items-center justify-between gap-3 border-b border-line-2 px-3.5 ${TONES[tone]}`}>
      <span className="font-mono text-[12px] leading-none font-bold tracking-[.12em] text-sand">{title}</span>
      <span className="font-mono text-xs leading-none text-umber">{aside}</span>
    </div>
  )
}
