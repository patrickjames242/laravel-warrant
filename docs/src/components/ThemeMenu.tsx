import {
  FloatingFocusManager,
  FloatingPortal,
  autoUpdate,
  flip,
  offset,
  shift,
  useClick,
  useDismiss,
  useFloating,
  useInteractions,
  useListNavigation,
  useRole,
  useTransitionStyles,
} from '@floating-ui/react'
import type { ReactNode } from 'react'
import { useCallback, useRef, useState } from 'react'
import type { ThemePreference } from '../lib/theme'
import { useTheme } from '../lib/theme'

const CHOICES: { key: ThemePreference; label: string; icon: ReactNode }[] = [
  { key: 'light', label: 'Light', icon: <SunIcon /> },
  { key: 'dark', label: 'Dark', icon: <MoonIcon /> },
  { key: 'system', label: 'System', icon: <SystemIcon /> },
]

/**
 * The header's theme button and the menu it opens: light, dark, or whichever
 * the reader's system uses. The button shows the theme the page is drawn in,
 * so while the system's is followed it is the sun or the moon that it resolves
 * to; the menu keeps one icon per choice.
 */
export function ThemeMenu() {
  const { theme, preference, setPreference } = useTheme()
  const [open, setOpen] = useState(false)
  const [activeIndex, setActiveIndex] = useState<number | null>(null)
  const items = useRef<(HTMLElement | null)[]>([])
  const chosenIndex = CHOICES.findIndex((choice) => choice.key === preference)
  const chosen = CHOICES[chosenIndex] ?? CHOICES[2]

  const { refs, floatingStyles, context } = useFloating({
    open,
    onOpenChange: setOpen,
    placement: 'bottom-end',
    whileElementsMounted: autoUpdate,
    middleware: [offset(8), flip({ padding: 12 }), shift({ padding: 12 })],
  })
  const setReference = useCallback(
    (node: HTMLElement | null) => {
      refs.setReference(node)
    },
    [refs],
  )
  const setFloating = useCallback(
    (node: HTMLElement | null) => {
      refs.setFloating(node)
    },
    [refs],
  )
  // It grows out of the button's corner and fades, opening a little slower than it closes.
  const { isMounted, styles: transitionStyles } = useTransitionStyles(context, {
    duration: { open: 200, close: 140 },
    initial: { opacity: 0, transform: 'translateY(-6px) scale(0.97)' },
    common: { transformOrigin: 'top right', transitionTimingFunction: 'var(--ease-glide)' },
  })
  const { getReferenceProps, getFloatingProps, getItemProps } = useInteractions([
    useClick(context),
    useDismiss(context),
    useRole(context, { role: 'menu' }),
    useListNavigation(context, {
      listRef: items,
      activeIndex,
      selectedIndex: chosenIndex,
      onNavigate: setActiveIndex,
      loop: true,
      focusItemOnOpen: true,
    }),
  ])

  return (
    <>
      <button
        ref={setReference}
        type="button"
        aria-label={`Theme: ${chosen?.label ?? 'System'}`}
        title="Theme"
        className={`grid size-9 flex-none cursor-pointer place-items-center rounded-md border bg-surface light:bg-surface/45 hover:border-line-5 hover:text-cream ${
          open ? 'border-line-5 text-cream' : 'border-line-3 text-sand'
        }`}
        {...getReferenceProps()}
      >
        {theme === 'light' ? <SunIcon /> : <MoonIcon />}
      </button>

      {isMounted && (
        <FloatingPortal>
          {/*
            The chosen theme is focused on opening by the list navigation, which
            focuses without scrolling. The focus manager's own initial focus would
            scroll to it, and as it sits within the page's scroll padding, under the
            sticky header, that scrolls the page.
          */}
          <FloatingFocusManager context={context} modal={false} initialFocus={-1}>
            <div ref={setFloating} style={floatingStyles} className="z-[60]" {...getFloatingProps()}>
              <div
                style={transitionStyles}
                className="grid w-38 gap-0.5 rounded-[11px] border border-line-3 bg-ink p-1.5 text-sand shadow-[0_10px_28px_-14px_color-mix(in_srgb,var(--color-shade)_55%,transparent)] light:border-pane-edge"
              >
                {CHOICES.map((choice, index) => {
                  const on = choice.key === preference
                  return (
                    <button
                      key={choice.key}
                      ref={(node) => {
                        items.current[index] = node
                      }}
                      type="button"
                      role="menuitemradio"
                      aria-checked={on}
                      tabIndex={activeIndex === index ? 0 : -1}
                      className={`flex h-8.5 w-full cursor-pointer items-center gap-2.5 rounded-md px-2.5 text-left text-[14.5px] font-medium outline-none hover:bg-cream/6 focus-visible:bg-cream/6 ${
                        on ? 'text-coral' : 'text-tan hover:text-cream'
                      }`}
                      {...getItemProps({
                        onClick: () => {
                          setPreference(choice.key)
                          setOpen(false)
                        },
                      })}
                    >
                      {choice.icon}
                      <span className="flex-1">{choice.label}</span>
                      {on && <CheckIcon />}
                    </button>
                  )
                })}
              </div>
            </div>
          </FloatingFocusManager>
        </FloatingPortal>
      )}
    </>
  )
}

function SunIcon() {
  return (
    <svg aria-hidden width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
      <circle cx="12" cy="12" r="4" />
      <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" />
    </svg>
  )
}

function MoonIcon() {
  return (
    <svg aria-hidden width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M20.5 14.5A8.5 8.5 0 1 1 9.5 3.5a7 7 0 0 0 11 11z" />
    </svg>
  )
}

/** A monitor, for the system's own theme. */
function SystemIcon() {
  return (
    <svg aria-hidden width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <rect x="2.5" y="3.5" width="19" height="13" rx="2" />
      <path d="M8 21h8M12 16.5V21" />
    </svg>
  )
}

function CheckIcon() {
  return (
    <svg aria-hidden width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.6" strokeLinecap="round" strokeLinejoin="round">
      <path d="M5 12.5l4.5 4.5L19 7.5" />
    </svg>
  )
}
