export function Logo({ label = 'Warrant' }: { label?: string }) {
  return (
    <span className="flex items-center gap-2.5">
      <span className="grid size-5.5 place-items-center rounded-sm bg-coral-strong font-mono text-[14.5px] leading-none font-extrabold text-ink">
        if
      </span>
      <span className="text-[18.5px] leading-none font-bold tracking-[-0.02em]">{label}</span>
    </span>
  )
}
