import * as vscode from 'vscode'

/**
 * Where PHP declares a condition, template, ability or schema, read from the
 * text alone: VS Code holds no PHP syntax tree an extension can ask. Each
 * reading names a member as the language server knows it, by its file and the
 * method or constant, or a schema's class, and the server says whether that
 * member declares anything. So a reading here that names a member declaring
 * nothing costs one request that answers null, never a wrong answer.
 */

/**
 * A member of a PHP file named at a position.
 *
 * - `method`, `constant` and `class`: the cursor is on the name a method,
 *   constant or class is declared with.
 * - `key`: on the string a condition's or template's attribute is given as
 *   its key, `#[RowCondition(key: 'in_folder')]`, whose method is `member`.
 * - `value`: on the string an ability constant is given, `const EDIT = 'edit'`.
 *
 * `range` is the name, or the string's contents without its quotes, and
 * `text` what is written there.
 */
export interface PhpMember {
  kind: 'method' | 'constant' | 'class' | 'key' | 'value'
  member: string
  range: vscode.Range
  text: string
}

/** Where the name of a condition, template or ability is written in its file. */
export interface DeclarationSite {
  kind: 'method' | 'key' | 'value' | 'constant' | 'class'
  range: vscode.Range
}

const IDENTIFIER = /[A-Za-z_\x80-￿][\w\x80-￿]*/

/** A single- or double-quoted PHP string on one line. */
const STRING = /'(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*"/g

/** A constant's declaration up to its name, with the type PHP 8.3 lets it have. */
const CONST_BEFORE_NAME = /\bconst\s+(?:[\w\\?|]+\s+)?$/
const CONST_BEFORE_VALUE = /\bconst\s+(?:[\w\\?|]+\s+)?([A-Za-z_]\w*)\s*=\s*$/

/** The method the attribute ending before this text belongs to. */
const METHOD_AFTER_ATTRIBUTE = /^[^;{}]*?\bfunction\s+&?\s*([A-Za-z_]\w*)\s*\(/

/** How far around a string an attribute and the method it belongs to are looked for. */
const REACH = 600

/** The member named at `position`, or undefined when the cursor is on none. */
export function memberAt(document: vscode.TextDocument, position: vscode.Position): PhpMember | undefined {
  const line = document.lineAt(position.line).text

  for (const string of line.matchAll(STRING)) {
    const start = string.index
    const end = start + string[0].length

    if (position.character > start && position.character < end) {
      return stringMember(document, new vscode.Range(position.line, start + 1, position.line, end - 1))
    }
  }

  const word = document.getWordRangeAtPosition(position, IDENTIFIER)
  if (word === undefined) {
    return undefined
  }

  const text = document.getText(word)
  const before = line.slice(0, word.start.character)

  if (/\bfunction\s+&?\s*$/.test(before)) {
    return { kind: 'method', member: text, range: word, text }
  }

  if (CONST_BEFORE_NAME.test(before)) {
    return { kind: 'constant', member: text, range: word, text }
  }

  if (/(?<!::\s*)\b(?:class|enum)\s+$/.test(before) && !/\bnew\s+class\s+$/.test(before)) {
    return { kind: 'class', member: qualified(document.getText(), text), range: word, text }
  }

  return undefined
}

/**
 * The members of `document` that may declare a condition, template, row
 * relation, ability or schema: each method and constant carrying an attribute,
 * and each class.
 */
export function membersOf(document: vscode.TextDocument): PhpMember[] {
  const text = document.getText()
  const members: PhpMember[] = []
  const add = (kind: PhpMember['kind'], member: string, name: string, offset: number): void => {
    const range = new vscode.Range(document.positionAt(offset), document.positionAt(offset + name.length))
    members.push({ kind, member, range, text: name })
  }

  for (const match of text.matchAll(/\bfunction\s+&?\s*([A-Za-z_]\w*)\s*\(/g)) {
    if (hasAttribute(text, match.index)) {
      add('method', match[1], match[1], match.index + match[0].lastIndexOf(match[1]))
    }
  }

  for (const match of text.matchAll(/\bconst\s+(?:[\w\\?|]+\s+)?([A-Za-z_]\w*)\s*=/g)) {
    if (hasAttribute(text, match.index)) {
      add('constant', match[1], match[1], match.index + match[0].lastIndexOf(match[1]))
    }
  }

  for (const match of text.matchAll(/(?<!\bnew\s+|::\s*)\bclass\s+([A-Za-z_]\w*)/g)) {
    if (!['extends', 'implements'].includes(match[1])) {
      add('class', qualified(text, match[1]), match[1], match.index + match[0].lastIndexOf(match[1]))
    }
  }

  return members
}

/**
 * Where `document` writes the name of the `kind` that `member` declares and
 * rule text calls `name`: an ability constant's value, or failing that the
 * constant; a condition's, template's or row relation's key, when its attribute
 * gives one, or else its method; a schema's class. Undefined when `member` is
 * not found.
 */
export function declarationSite(document: vscode.TextDocument, kind: string, member: string, name: string): DeclarationSite | undefined {
  const text = document.getText()
  const at = (siteKind: DeclarationSite['kind'], offset: number, length: number): DeclarationSite => ({
    kind: siteKind,
    range: new vscode.Range(document.positionAt(offset), document.positionAt(offset + length)),
  })

  if (kind === 'schema') {
    const short = member.slice(member.lastIndexOf('\\') + 1)
    const match = new RegExp(String.raw`\bclass\s+(${escape(short)})\b`).exec(text)

    return match === null ? undefined : at('class', match.index + match[0].length - short.length, short.length)
  }

  if (kind === 'ability') {
    const match = new RegExp(String.raw`\bconst\s+(?:[\w\\?|]+\s+)?(${escape(member)})\s*=\s*(['"])((?:(?!\2)[^\\]|\\.)*)\2`).exec(text)
    if (match === null) {
      return undefined
    }

    return match[3] === name
      ? at('value', match.index + match[0].length - match[3].length - 1, match[3].length)
      : at('constant', match.index + match[0].indexOf(match[1], 'const'.length), match[1].length)
  }

  const method = new RegExp(String.raw`\bfunction\s+&?\s*(${escape(member)})\s*\(`, 'i').exec(text)
  if (method === null) {
    return undefined
  }

  const attributes = text.slice(segmentStart(text, method.index), method.index)
  const key = new RegExp(String.raw`(['"])${escape(name)}\1`).exec(attributes)
  const keyStart = key === null ? -1 : segmentStart(text, method.index) + key.index + 1

  if (key !== null && stringMember(document, new vscode.Range(document.positionAt(keyStart), document.positionAt(keyStart + name.length)))?.kind === 'key') {
    return at('key', keyStart, name.length)
  }

  return at('method', method.index + method[0].lastIndexOf(method[1]), method[1].length)
}

/** The position of `offset` in `text`, for a file no editor has open. */
export function positionIn(text: string, offset: number): vscode.Position {
  let line = 0
  let lineStart = 0

  for (let i = text.indexOf('\n'); i !== -1 && i < offset; i = text.indexOf('\n', i + 1)) {
    line++
    lineStart = i + 1
  }

  return new vscode.Position(line, offset - lineStart)
}

/**
 * What the string whose contents are `contents` is: a condition's, template's or
 * row relation's key, or an ability constant's value.
 *
 * A key is an attribute's first argument, or its `key:`. A `#[RowRelation]`
 * names its schema first, so its key by position is the second argument, after
 * the `::class`.
 */
function stringMember(document: vscode.TextDocument, contents: vscode.Range): PhpMember | undefined {
  const text = document.getText()
  const start = document.offsetAt(contents.start) - 1
  const end = document.offsetAt(contents.end) + 1
  const value = document.getText(contents)
  const before = text.slice(Math.max(0, start - REACH), start)

  const constant = CONST_BEFORE_VALUE.exec(before.slice(before.lastIndexOf('\n') + 1))
  if (constant !== null) {
    return { kind: 'value', member: constant[1], range: contents, text: value }
  }

  const attribute = before.slice(before.lastIndexOf('#['))
  const isKey = before.includes('#[') && !/[;{}\]]/.test(attribute.slice(2)) &&
    (/^#\[\s*\\?[\w\\]+\s*\(\s*$/.test(attribute) || /[(,]\s*key\s*:\s*$/.test(attribute) ||
      /^#\[\s*\\?(?:[\w\\]*\\)?RowRelation\s*\(\s*\\?[\w\\]+::class\s*,\s*$/.test(attribute))
  const method = isKey ? METHOD_AFTER_ATTRIBUTE.exec(text.slice(end, end + REACH)) : null

  return method === null ? undefined : { kind: 'key', member: method[1], range: contents, text: value }
}

/** Whether attributes are written between the member at `offset` and the statement before it. */
function hasAttribute(text: string, offset: number): boolean {
  return text.slice(segmentStart(text, offset), offset).includes('#[')
}

/** Where the statement holding `offset` starts: after the last `;`, `{` or `}` before it. */
function segmentStart(text: string, offset: number): number {
  const before = text.slice(Math.max(0, offset - REACH), offset)

  return offset - before.length + Math.max(before.lastIndexOf(';'), before.lastIndexOf('{'), before.lastIndexOf('}')) + 1
}

/** `name` qualified by the namespace `text` declares. */
function qualified(text: string, name: string): string {
  const namespace = /^\s*namespace\s+([\w\\]+)\s*[;{]/m.exec(text)

  return namespace === null ? name : `${namespace[1]}\\${name}`
}

export function escape(text: string): string {
  return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}
