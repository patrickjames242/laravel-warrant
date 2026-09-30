import type { Extension, Range, Text } from '@codemirror/state'
import { RangeSet, StateEffect, StateField } from '@codemirror/state'
import { EditorView, GutterMarker, gutter } from '@codemirror/view'
import { diffLines } from 'diff'

type ChangeKind = 'added' | 'modified' | 'deleted' | 'deleted-after'

const TITLES: Record<ChangeKind, string> = {
  added: 'Added since the last commit',
  modified: 'Changed since the last commit',
  deleted: 'Lines deleted above, since the last commit',
  'deleted-after': 'Lines deleted below, since the last commit',
}

class ChangeMarker extends GutterMarker {
  constructor(readonly kind: ChangeKind) {
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

const MARKERS: Record<ChangeKind, ChangeMarker> = {
  added: new ChangeMarker('added'),
  modified: new ChangeMarker('modified'),
  deleted: new ChangeMarker('deleted'),
  'deleted-after': new ChangeMarker('deleted-after'),
}

/** Gives the editor a new committed version of the file to compare against, or `null` for a file git does not track. */
export const setCommitted = StateEffect.define<string | null>()

interface Changes {
  committed: string | null
  markers: RangeSet<GutterMarker>
}

/**
 * Where the text differs from the committed version, line by line. Lines that
 * replace committed ones are modified; lines with nothing in their place are
 * added; committed lines with nothing in their place leave a mark on the line
 * that now follows them, or on the last line when they ran to the end. A file
 * git does not track is new, all of it.
 */
function markersFor(doc: Text, committed: string | null): RangeSet<GutterMarker> {
  const marks: Range<GutterMarker>[] = []
  const markLines = (from: number, count: number, marker: ChangeMarker) => {
    for (let n = from; n < from + count && n <= doc.lines; n++) marks.push(marker.range(doc.line(n).from))
  }

  if (committed === null) {
    markLines(1, doc.lines, MARKERS.added)
    return RangeSet.of(marks, true)
  }

  const parts = diffLines(committed, doc.toString())
  let line = 1
  for (let i = 0; i < parts.length; i++) {
    const part = parts[i]
    if (!part) continue
    const next = parts[i + 1]

    if (part.removed && next?.added) {
      markLines(line, next.count, MARKERS.modified)
      line += next.count
      i++
    } else if (part.removed) {
      if (line <= doc.lines) marks.push(MARKERS.deleted.range(doc.line(line).from))
      else marks.push(MARKERS['deleted-after'].range(doc.line(doc.lines).from))
    } else if (part.added) {
      markLines(line, part.count, MARKERS.added)
      line += part.count
    } else {
      line += part.count
    }
  }

  return RangeSet.of(marks, true)
}

function changesField(committed: string | null) {
  return StateField.define<Changes>({
    create: (state) => ({ committed, markers: markersFor(state.doc, committed) }),
    update(value, transaction) {
      let next = value.committed
      for (const effect of transaction.effects) if (effect.is(setCommitted)) next = effect.value
      if (next === value.committed && !transaction.docChanged) return value
      return { committed: next, markers: markersFor(transaction.state.doc, next) }
    },
  })
}

const theme = EditorView.theme({
  '.cm-changes-gutter .cm-gutterElement': { width: '6px', padding: '0', position: 'relative' },
  '.cm-change': { position: 'absolute', left: '1px' },
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
})

/**
 * Marks, left of the line numbers, every line that differs from the last
 * commit, the way an editor's source-control gutter does. It compares the
 * text as it is being typed, saved or not, so the marks follow each edit.
 * `setCommitted` gives it a new version to compare against, such as after a
 * commit or a rollback.
 */
export function gitChanges(committed: string | null): Extension {
  const field = changesField(committed)
  return [
    field,
    gutter({
      class: 'cm-changes-gutter',
      markers: (view) => view.state.field(field).markers,
      initialSpacer: () => MARKERS.added,
    }),
    theme,
  ]
}
