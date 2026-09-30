import type { ChangeSpec, EditorState, Extension, Range, Text } from '@codemirror/state'
import { Prec, RangeSet, StateEffect, StateField } from '@codemirror/state'
import { Decoration, EditorView, GutterMarker, WidgetType, gutter, keymap } from '@codemirror/view'
import { diffLines } from 'diff'

type MarkerKind = 'added' | 'modified' | 'deleted' | 'deleted-after'

/**
 * One run of lines that differs from the last commit. Added and modified runs
 * cover lines of the text; a deletion covers none and stands before the line
 * that now follows the removed lines, or past the last line when they ran to
 * the end.
 */
interface Hunk {
  kind: 'added' | 'modified' | 'deleted'
  /** The first line it covers, counting from 1, or for a deletion the line it stands before. */
  line: number
  /** How many lines of the text it covers: none for a deletion. */
  count: number
  /** The committed lines it took the place of, each with the line break it had. Empty when it added lines. */
  committed: string
}

const TITLES: Record<MarkerKind, string> = {
  added: 'Added since the last commit. Click to see or revert it.',
  modified: 'Changed since the last commit. Click to see or revert it.',
  deleted: 'Lines deleted above, since the last commit. Click to see or revert it.',
  'deleted-after': 'Lines deleted below, since the last commit. Click to see or revert it.',
}

const LABELS: Record<Hunk['kind'], string> = {
  added: 'Added since the last commit',
  modified: 'Changed since the last commit',
  deleted: 'Deleted since the last commit',
}

class ChangeMarker extends GutterMarker {
  constructor(readonly kind: MarkerKind) {
    super()
  }

  override eq(other: GutterMarker): boolean {
    return other instanceof ChangeMarker && other.kind === this.kind
  }

  override toDOM(): Node {
    const mark = document.createElement('div')
    mark.className = `cm-change cm-change-${this.kind}`
    mark.title = TITLES[this.kind]
    return mark
  }
}

const MARKERS: Record<MarkerKind, ChangeMarker> = {
  added: new ChangeMarker('added'),
  modified: new ChangeMarker('modified'),
  deleted: new ChangeMarker('deleted'),
  'deleted-after': new ChangeMarker('deleted-after'),
}

/** Gives the editor a new committed version of the file to compare against, or `null` for a file git does not track. */
export const setCommitted = StateEffect.define<string | null>()

/** Opens the change whose mark is on the line starting at this position, or closes the open one with `null`. */
const setPeek = StateEffect.define<number | null>()

interface Changes {
  committed: string | null
  hunks: Hunk[]
  markers: RangeSet<GutterMarker>
}

/**
 * Where the text differs from the committed version. Lines that replace
 * committed ones are modified; lines with nothing in their place are added;
 * committed lines with nothing in their place are a deletion. A file git does
 * not track is new, all of it, with nothing to go back to.
 */
function analyse(doc: Text, committed: string | null): Changes {
  const hunks: Hunk[] = []

  if (committed !== null) {
    const parts = diffLines(committed, doc.toString())
    let line = 1
    for (let i = 0; i < parts.length; i++) {
      const part = parts[i]
      if (!part) continue
      const next = parts[i + 1]

      if (part.removed && next?.added) {
        hunks.push({ kind: 'modified', line, count: next.count, committed: part.value })
        line += next.count
        i++
      } else if (part.removed) {
        hunks.push({ kind: 'deleted', line, count: 0, committed: part.value })
      } else if (part.added) {
        hunks.push({ kind: 'added', line, count: part.count, committed: '' })
        line += part.count
      } else {
        line += part.count
      }
    }
  }

  const marks: Range<GutterMarker>[] = []
  const markLines = (from: number, count: number, marker: ChangeMarker) => {
    for (let n = from; n < from + count && n <= doc.lines; n++) marks.push(marker.range(doc.line(n).from))
  }
  if (committed === null) markLines(1, doc.lines, MARKERS.added)
  for (const hunk of hunks) {
    if (hunk.count > 0) markLines(hunk.line, hunk.count, MARKERS[hunk.kind === 'added' ? 'added' : 'modified'])
    else if (hunk.line <= doc.lines) marks.push(MARKERS.deleted.range(doc.line(hunk.line).from))
    else marks.push(MARKERS['deleted-after'].range(doc.line(doc.lines).from))
  }

  return { committed, hunks, markers: RangeSet.of(marks, true) }
}

/** The line a hunk's mark is drawn on. */
function markedLine(hunk: Hunk, doc: Text): number {
  return hunk.count > 0 ? hunk.line : Math.min(hunk.line, doc.lines)
}

function hunkOnLine(hunks: readonly Hunk[], line: number, doc: Text): Hunk | undefined {
  return hunks.find((hunk) =>
    hunk.count > 0 ? line >= hunk.line && line < hunk.line + hunk.count : line === markedLine(hunk, doc),
  )
}

/** The edit that puts one hunk back as it was committed, leaving every other change as it is. */
function revertOf(doc: Text, hunk: Hunk): ChangeSpec {
  if (hunk.count === 0) {
    if (hunk.line <= doc.lines) return { from: doc.line(hunk.line).from, insert: hunk.committed }
    // Removed from the end: the text's own last line may have no break after it to follow.
    const endsWithBreak = doc.length === 0 || doc.sliceString(doc.length - 1) === '\n'
    return { from: doc.length, insert: endsWithBreak ? hunk.committed : `\n${hunk.committed.replace(/\n$/, '')}` }
  }

  const first = doc.line(hunk.line)
  const last = doc.line(hunk.line + hunk.count - 1)
  if (hunk.committed === '') {
    // Added lines go with the line break that separates them from their neighbours.
    return last.to < doc.length ? { from: first.from, to: last.to + 1 } : { from: Math.max(0, first.from - 1), to: last.to }
  }
  return { from: first.from, to: last.to, insert: hunk.committed.replace(/\n$/, '') }
}

/** The panel under an open change: what the commit had there, and a way to put it back. */
class PeekWidget extends WidgetType {
  constructor(
    readonly hunk: Hunk,
    readonly onRevert: (view: EditorView) => void,
    readonly onClose: (view: EditorView) => void,
  ) {
    super()
  }

  override eq(other: WidgetType): boolean {
    return (
      other instanceof PeekWidget &&
      other.hunk.kind === this.hunk.kind &&
      other.hunk.line === this.hunk.line &&
      other.hunk.count === this.hunk.count &&
      other.hunk.committed === this.hunk.committed
    )
  }

  override toDOM(view: EditorView): HTMLElement {
    const panel = document.createElement('div')
    panel.className = `cm-peek cm-peek-${this.hunk.kind}`
    panel.setAttribute('role', 'region')
    panel.setAttribute('aria-label', LABELS[this.hunk.kind])

    const header = document.createElement('div')
    header.className = 'cm-peek-header'
    const label = document.createElement('span')
    label.className = 'cm-peek-label'
    label.textContent = LABELS[this.hunk.kind]
    const revert = document.createElement('button')
    revert.type = 'button'
    revert.className = 'cm-peek-revert'
    revert.textContent = 'Revert this change'
    revert.addEventListener('click', () => {
      this.onRevert(view)
    })
    const close = document.createElement('button')
    close.type = 'button'
    close.className = 'cm-peek-close'
    close.textContent = '×'
    close.setAttribute('aria-label', 'Close')
    close.title = 'Close (Esc)'
    close.addEventListener('click', () => {
      this.onClose(view)
    })
    header.append(label, revert, close)

    const body = document.createElement('div')
    body.className = 'cm-peek-body'
    if (this.hunk.committed === '') {
      const note = document.createElement('div')
      note.className = 'cm-peek-note'
      note.textContent =
        this.hunk.count === 1 ? 'This line is new since the last commit.' : 'These lines are new since the last commit.'
      body.append(note)
    } else {
      for (const text of this.hunk.committed.replace(/\n$/, '').split('\n')) {
        const row = document.createElement('div')
        row.className = 'cm-peek-removed'
        row.textContent = text
        body.append(row)
      }
    }

    panel.append(header, body)
    return panel
  }
}

const theme = EditorView.theme({
  '.cm-changes-gutter .cm-gutterElement': { width: '8px', padding: '0', position: 'relative' },
  '.cm-changes-gutter .cm-gutterElement:has(.cm-change)': { cursor: 'pointer' },
  '.cm-change': { position: 'absolute', left: '2px' },
  '.cm-change-added, .cm-change-modified': { top: '0', bottom: '0', width: '3px', borderRadius: '1px' },
  '.cm-change-added': { backgroundColor: 'var(--color-change-added)' },
  '.cm-change-modified': { backgroundColor: 'var(--color-change-modified)' },
  '.cm-change-deleted, .cm-change-deleted-after': {
    width: '0',
    height: '0',
    borderLeft: '6px solid var(--color-change-deleted)',
    borderTop: '4px solid transparent',
    borderBottom: '4px solid transparent',
  },
  '.cm-change-deleted': { top: '-4px' },
  '.cm-change-deleted-after': { bottom: '-4px' },
  '.cm-changes-gutter .cm-gutterElement:hover .cm-change-added, .cm-changes-gutter .cm-gutterElement:hover .cm-change-modified':
    { width: '5px' },

  '.cm-peek-line': { backgroundColor: 'color-mix(in srgb, var(--color-change-modified) 9%, transparent)' },
  '.cm-peek': {
    margin: '6px 0 8px',
    border: '1px solid var(--color-line-4)',
    borderLeft: '3px solid var(--color-change-modified)',
    borderRadius: '6px',
    backgroundColor: 'var(--color-surface)',
    overflow: 'hidden',
  },
  '.cm-peek-added': { borderLeftColor: 'var(--color-change-added)' },
  '.cm-peek-deleted': { borderLeftColor: 'var(--color-change-deleted)' },
  '.cm-peek-header': {
    display: 'flex',
    alignItems: 'center',
    gap: '8px',
    padding: '6px 8px 6px 12px',
    borderBottom: '1px solid var(--color-line-2)',
    fontFamily: 'var(--font-sans)',
  },
  '.cm-peek-label': {
    flex: '1',
    fontFamily: 'var(--font-mono)',
    fontSize: '11.5px',
    letterSpacing: '.08em',
    textTransform: 'uppercase',
    color: 'var(--color-taupe)',
  },
  '.cm-peek-revert, .cm-peek-close': {
    height: '26px',
    borderRadius: '5px',
    border: '1px solid var(--color-line-5)',
    background: 'transparent',
    color: 'var(--color-cream)',
    cursor: 'pointer',
    font: 'inherit',
  },
  '.cm-peek-revert': { padding: '0 10px', fontSize: '13px', fontWeight: '500' },
  '.cm-peek-revert:hover': { borderColor: 'var(--color-coral)', color: 'var(--color-coral-soft)' },
  '.cm-peek-close': { width: '26px', fontSize: '16px', lineHeight: '1', color: 'var(--color-tan)' },
  '.cm-peek-close:hover': { borderColor: 'var(--color-taupe)' },
  '.cm-peek-body': { padding: '6px 0', fontFamily: 'var(--font-mono)', fontSize: '13px', lineHeight: '1.65' },
  '.cm-peek-removed': {
    padding: '0 12px 0 28px',
    whiteSpace: 'pre-wrap',
    color: 'var(--color-sand)',
    backgroundColor: 'color-mix(in srgb, var(--color-change-deleted) 9%, transparent)',
    position: 'relative',
  },
  '.cm-peek-removed::before': { content: '"−"', position: 'absolute', left: '12px', color: 'var(--color-change-deleted)' },
  '.cm-peek-note': { padding: '0 12px', fontFamily: 'var(--font-sans)', fontSize: '14px', color: 'var(--color-tan)' },
})

/**
 * Marks, left of the line numbers, every line that differs from the last
 * commit, the way an editor's source-control gutter does. It compares the
 * text as it is being typed, saved or not, so the marks follow each edit.
 * `setCommitted` gives it a new version to compare against, such as after a
 * commit or a rollback.
 *
 * Clicking a mark opens its change under it: the committed lines it replaced,
 * and a button that puts just that change back as it was committed. Esc or
 * any edit closes it.
 */
export function gitChanges(committed: string | null): Extension {
  const changes = StateField.define<Changes>({
    create: (state) => analyse(state.doc, committed),
    update(value, transaction) {
      let next = value.committed
      for (const effect of transaction.effects) if (effect.is(setCommitted)) next = effect.value
      if (next === value.committed && !transaction.docChanged) return value
      return analyse(transaction.state.doc, next)
    },
  })

  const peek = StateField.define<number | null>({
    create: () => null,
    update(value, transaction) {
      for (const effect of transaction.effects) if (effect.is(setPeek)) return effect.value
      return transaction.docChanged ? null : value
    },
  })

  const close = (view: EditorView) => {
    view.dispatch({ effects: setPeek.of(null) })
  }

  const openHunk = (state: EditorState): Hunk | undefined => {
    const at = state.field(peek)
    if (at === null) return undefined
    return hunkOnLine(state.field(changes).hunks, state.doc.lineAt(at).number, state.doc)
  }

  const revert = (view: EditorView) => {
    const hunk = openHunk(view.state)
    if (!hunk) return
    view.dispatch({ changes: revertOf(view.state.doc, hunk), effects: setPeek.of(null), userEvent: 'revert' })
    view.focus()
  }

  const decorations = EditorView.decorations.compute([changes, peek], (state) => {
    const hunk = openHunk(state)
    if (!hunk) return Decoration.none
    const { doc } = state
    const ranges: Range<Decoration>[] = []
    for (let n = hunk.line; n < hunk.line + hunk.count; n++) {
      ranges.push(Decoration.line({ class: 'cm-peek-line' }).range(doc.line(n).from))
    }
    const widget = Decoration.widget({ widget: new PeekWidget(hunk, revert, close), block: true, side: 1 })
    if (hunk.count > 0) ranges.push(widget.range(doc.line(hunk.line + hunk.count - 1).to))
    else if (hunk.line <= doc.lines) {
      ranges.push(Decoration.widget({ widget: new PeekWidget(hunk, revert, close), block: true, side: -1 }).range(doc.line(hunk.line).from))
    } else ranges.push(widget.range(doc.length))
    return Decoration.set(ranges, true)
  })

  return [
    changes,
    peek,
    decorations,
    gutter({
      class: 'cm-changes-gutter',
      markers: (view) => view.state.field(changes).markers,
      initialSpacer: () => MARKERS.added,
      domEventHandlers: {
        mousedown(view, block) {
          const { doc } = view.state
          const line = doc.lineAt(block.from)
          const hunk = hunkOnLine(view.state.field(changes).hunks, line.number, doc)
          if (!hunk) return false
          // A second click on the open change closes it.
          const open = openHunk(view.state)
          const same = open !== undefined && markedLine(open, doc) === markedLine(hunk, doc)
          view.dispatch({ effects: setPeek.of(same ? null : doc.line(markedLine(hunk, doc)).from) })
          return true
        },
      },
    }),
    Prec.highest(
      keymap.of([
        {
          key: 'Escape',
          run: (view) => {
            if (view.state.field(peek) === null) return false
            close(view)
            return true
          },
        },
      ]),
    ),
    theme,
  ]
}
