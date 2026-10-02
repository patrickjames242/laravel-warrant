import { defaultKeymap, history, historyKeymap, indentWithTab } from '@codemirror/commands'
import { markdown, markdownLanguage } from '@codemirror/lang-markdown'
import { yamlFrontmatter } from '@codemirror/lang-yaml'
import { HighlightStyle, syntaxHighlighting, syntaxTree } from '@codemirror/language'
import { EditorState, RangeSetBuilder } from '@codemirror/state'
import type { DecorationSet, ViewUpdate } from '@codemirror/view'
import {
  Decoration,
  EditorView,
  ViewPlugin,
  drawSelection,
  highlightActiveLine,
  highlightActiveLineGutter,
  keymap,
  lineNumbers,
} from '@codemirror/view'
import { tags } from '@lezer/highlight'
import { useEffect, useEffectEvent, useRef } from 'react'
import { gitChanges, setCommitted } from './gitChanges'

/** Markdown in the site's colours: the same tokens the rendered page uses for the same things. */
const highlightStyle = HighlightStyle.define([
  { tag: tags.heading, color: 'var(--color-cream)', fontWeight: '700' },
  { tag: tags.strong, color: 'var(--color-cream)', fontWeight: '600' },
  { tag: tags.emphasis, color: 'var(--color-sand)', fontStyle: 'italic' },
  { tag: tags.strikethrough, textDecoration: 'line-through' },
  { tag: [tags.link, tags.url], color: 'var(--color-coral-soft)' },
  { tag: tags.monospace, color: 'var(--color-editor-code)' },
  { tag: tags.quote, color: 'var(--color-sand)' },
  { tag: tags.list, color: 'var(--color-coral)' },
  { tag: [tags.processingInstruction, tags.contentSeparator, tags.meta], color: 'var(--color-umber)' },
  { tag: tags.comment, color: 'var(--color-code-comment)' },
  // The frontmatter's YAML.
  { tag: [tags.propertyName, tags.definition(tags.propertyName)], color: 'var(--color-taupe)' },
  // Only quoted values: plain ones, like a paragraph's text, are tagged content and stay the body colour.
  { tag: tags.string, color: 'var(--color-gold)' },
  { tag: [tags.number, tags.bool, tags.null], color: 'var(--color-gold)' },
])

const theme = EditorView.theme(
  {
    '&': { height: '100%', backgroundColor: 'transparent', color: 'var(--color-sand)', fontSize: '15.5px' },
    '&.cm-focused': { outline: 'none' },
    '.cm-scroller': { fontFamily: 'var(--font-mono)', lineHeight: '1.7', overscrollBehavior: 'contain' },
    '.cm-content': { padding: '16px 0 40vh', caretColor: 'var(--color-coral)' },
    '.cm-line': { padding: '0 20px 0 12px' },
    '.cm-cursor, .cm-dropCursor': { borderLeftColor: 'var(--color-coral)', borderLeftWidth: '2px' },
    '&.cm-focused .cm-selectionBackground, .cm-selectionBackground, .cm-content ::selection': {
      backgroundColor: 'color-mix(in srgb, var(--color-coral) 26%, transparent)',
    },
    '.cm-activeLine': { backgroundColor: 'color-mix(in srgb, var(--color-coral) 5%, transparent)' },
    '.cm-gutters': {
      backgroundColor: 'transparent',
      color: 'var(--color-gutter)',
      border: 'none',
      paddingLeft: '6px',
    },
    '.cm-code-block': { backgroundColor: 'color-mix(in srgb, var(--color-cream) 5%, transparent)' },
    '.cm-activeLineGutter': { backgroundColor: 'transparent', color: 'var(--color-taupe)' },
  },
  { dark: true },
)

const codeBlockLine = Decoration.line({ class: 'cm-code-block' })

/** Marks every visible line of a fenced code block, fences included, so the block reads as one band. */
function codeBlockLines(view: EditorView): DecorationSet {
  const builder = new RangeSetBuilder<Decoration>()
  let lastLine = -1
  for (const { from, to } of view.visibleRanges) {
    syntaxTree(view.state).iterate({
      from,
      to,
      enter: (node) => {
        if (node.name !== 'FencedCode') return
        for (let pos = Math.max(node.from, from); pos <= Math.min(node.to, to); ) {
          const line = view.state.doc.lineAt(pos)
          if (line.number > lastLine) builder.add(line.from, line.from, codeBlockLine)
          lastLine = line.number
          pos = line.to + 1
        }
        return false
      },
    })
  }
  return builder.finish()
}

const codeBlocks = ViewPlugin.fromClass(
  class {
    decorations: DecorationSet

    constructor(view: EditorView) {
      this.decorations = codeBlockLines(view)
    }

    update(update: ViewUpdate) {
      if (update.docChanged || update.viewportChanged || syntaxTree(update.startState) !== syntaxTree(update.state)) {
        this.decorations = codeBlockLines(update.view)
      }
    }
  },
  { decorations: (plugin) => plugin.decorations },
)

interface MarkdownEditorProps {
  value: string
  /** The file as of the last commit, whose differences are marked beside the lines; `null` when git does not track it. */
  committed: string | null
  onChange: (value: string) => void
  /** Hands over the editor's view once it exists, and `null` when it goes. */
  onView?: (view: EditorView | null) => void
  label: string
}

/**
 * A CodeMirror editor for one page's Markdown. It starts with `value`, so its
 * undo history begins at the page as loaded. Typing reports the new text
 * through `onChange`, and a later `value` that differs from the editor's own
 * text, such as a revert or the file changing on disk, replaces it.
 */
export function MarkdownEditor({ value, committed, onChange, onView, label }: MarkdownEditorProps) {
  const host = useRef<HTMLDivElement>(null)
  const view = useRef<EditorView>(null)

  const reportChange = useEffectEvent((text: string) => {
    onChange(text)
  })
  const reportView = useEffectEvent((next: EditorView | null) => {
    onView?.(next)
  })
  const initialValue = useEffectEvent(() => value)
  const initialCommitted = useEffectEvent(() => committed)

  useEffect(() => {
    const parent = host.current
    if (!parent) return

    const editor = new EditorView({
      parent,
      state: EditorState.create({
        doc: initialValue(),
        extensions: [
          gitChanges(initialCommitted()),
          lineNumbers(),
          highlightActiveLineGutter(),
          history(),
          drawSelection(),
          highlightActiveLine(),
          EditorView.lineWrapping,
          // The frontmatter is YAML; read as Markdown, its closing `---` would make the block a heading.
          yamlFrontmatter({ content: markdown({ base: markdownLanguage }) }),
          syntaxHighlighting(highlightStyle),
          codeBlocks,
          keymap.of([...defaultKeymap, ...historyKeymap, indentWithTab]),
          theme,
          EditorView.contentAttributes.of({ 'aria-label': label }),
          EditorView.updateListener.of((update) => {
            if (update.docChanged) reportChange(update.state.doc.toString())
          }),
        ],
      }),
    })
    view.current = editor
    reportView(editor)

    return () => {
      reportView(null)
      view.current = null
      editor.destroy()
    }
  }, [label])

  useEffect(() => {
    view.current?.dispatch({ effects: setCommitted.of(committed) })
  }, [committed])

  // A value from outside replaces the text, as one step the editor's undo can take back.
  useEffect(() => {
    const editor = view.current
    if (!editor) return
    const current = editor.state.doc.toString()
    if (current !== value) editor.dispatch({ changes: { from: 0, to: current.length, insert: value } })
  }, [value])

  return <div ref={host} className="min-h-0 flex-1" />
}
