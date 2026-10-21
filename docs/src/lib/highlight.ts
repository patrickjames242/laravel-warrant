/** `plain` leaves every token uncoloured, for text such as an error message. */
export type Language = 'rule' | 'php' | 'sql' | 'plain'

export type TokenKind =
  | 'keyword'
  | 'variable'
  | 'property'
  | 'function'
  | 'class'
  | 'string'
  | 'comment'
  | 'punctuation'
  | 'number'
  | 'text'

export interface Token {
  text: string
  kind: TokenKind
  weight: 400 | 500 | 600
}

export interface Line {
  tokens: Token[]
}

const RULE_KEYWORDS = new Set(['if', 'when', 'otherwise', 'they', 'can', 'cannot', 'because', 'check', 'and', 'or', 'not', 'for', 'with', 'as'])
const RULE_CONSTANTS = new Set(['true', 'false', 'null'])
const PHP_KEYWORDS = new Set([
  'public',
  'function',
  'return',
  'if',
  'fn',
  'use',
  'new',
  'true',
  'false',
  'null',
  'static',
  'class',
  'extends',
  'const',
  'bool',
  'match',
  'implements',
  'unless',
])
const SQL_KEYWORDS = new Set([
  'select',
  'from',
  'where',
  'and',
  'or',
  'not',
  'in',
  'exists',
  'as',
  'true',
  'false',
  'null',
  'limit',
])

const TOKEN = /(\/\/.*|--.*|'[^']*'|"[^"]*"|@\w+|\$\w+|->|::|#\[|\w+|\s+|[^\w\s])/g

/**
 * A small tokenizer for the languages the site shows. PHP and SQL are coloured
 * by shape, which is enough for short, hand-written snippets and keeps the page
 * free of a full highlighter. Rule text is coloured by {@link highlightRule}.
 */
export function highlight(source: string, language: Language): Line[] {
  const lines = source.replace(/^\n/, '').replace(/\s+$/, '').split('\n')

  if (language === 'rule') return lines.map((line) => ({ tokens: highlightRule(line) }))

  return lines.map((line) => {
    let previous = ''

    const tokens = (line.match(TOKEN) ?? []).map((text): Token => {
      const token = classify(text, language, previous)
      if (!/^\s+$/.test(text)) previous = text
      return token
    })

    return { tokens }
  })
}

const RULE_TOKEN =
  /(#.*|'(?:\\.|[^'\\])*'?|"(?:\\.|[^"\\])*"?|@[A-Za-z_][\w-]*|:[A-Za-z_][\w-]*|(?<![\w-])-?\d+(?:\.\d+)?|[A-Za-z_][\w-]*|\s+|.)/g

const IDENTIFIER = /^[A-Za-z_][\w-]*$/

/**
 * One line of rule text, coloured as the VS Code grammar
 * (editors/vscode/syntaxes/warrant.tmLanguage.json) and the PhpStorm lexer
 * (editors/phpstorm/.../WarrantLexer.kt) colour it, so a rule on the site looks
 * the way it does in an editor:
 *
 * - keywords, `@include`, and the `! * =` operators as keywords;
 * - the schema after `for` (except an `@include`'s, which lists abilities), the frame in `@column <frame>.<column>`, and the
 *   ability list of a `can they <abilities> {` header as names of a type;
 * - `@context`, `@column`, `@sql`, `:name` and `?` as variables, and the column
 *   a `@column` names as a property;
 * - `true`, `false`, `null`, numbers and string escapes as constants;
 * - every other name, condition and ability alike, as a function.
 *
 * Like the VS Code grammar, the patterns that read more than one token match
 * within a line only.
 */
function highlightRule(line: string): Token[] {
  const tokens: Token[] = []

  for (const text of line.match(RULE_TOKEN) ?? []) {
    if (/^['"]/.test(text)) tokens.push(...stringTokens(text))
    else tokens.push(ruleToken(text))
  }

  const significant = tokens.filter((token) => token.kind !== 'text')
  const isName = (token: Token | undefined): token is Token => token?.kind === 'function'

  significant.forEach((token, n) => {
    const [next, second, third] = significant.slice(n + 1, n + 4)

    /* The `for` of an `@include` lists abilities, not a schema. */
    const inInclude = significant.slice(0, n).some((before) => before.text === '@include')
    if (token.text === 'for' && isName(next) && !inInclude) retag(next, 'class', 500)

    if (token.text === '@column' && isName(next)) {
      if (second?.text === '.' && isName(third)) {
        retag(next, 'class', 500)
        retag(third, 'property')
      } else {
        retag(next, 'property')
      }
    }

    if (token.text === '@context' && isName(next)) retag(next, 'property')

    if (token.text === 'can' && next?.text === 'they') abilityBlockHeader(significant.slice(n + 2))
  })

  return tokens
}

/**
 * The ability list of a `can they <abilities> {` header, retagged as names of a
 * type. `rest` is the significant tokens after `they`; nothing changes unless the
 * list runs to a `{` on the same line.
 */
function abilityBlockHeader(rest: Token[]) {
  const list: Token[] = []

  for (const token of rest) {
    if (token.text === '{') {
      for (const name of list) {
        if (name.text !== ',') retag(name, 'class', 500)
      }
      return
    }

    const expectsName = list.length % 2 === 0
    const fits = expectsName ? token.kind === 'function' || token.text === '*' : token.text === ','
    if (!fits) return
    list.push(token)
  }
}

function ruleToken(text: string): Token {
  const token = (kind: TokenKind, weight: Token['weight'] = 400): Token => ({ text, kind, weight })

  if (/^\s+$/.test(text)) return token('text')
  if (text.startsWith('#')) return token('comment')
  if (text === '@include') return token('keyword', 600)
  if (text.startsWith('@') || text.startsWith(':') || text === '?') return token('variable', 500)
  if (RULE_KEYWORDS.has(text)) return token('keyword', 600)
  if (RULE_CONSTANTS.has(text)) return token('number')
  if (/^-?\d/.test(text)) return token('number')
  if (IDENTIFIER.test(text)) return token('function')
  if (text === '!' || text === '*' || text === '=') return token('keyword', 500)
  return token('punctuation')
}

/** A string literal, with each `\'`, `\"` and `\\` escape in it set apart. */
function stringTokens(text: string): Token[] {
  return text
    .split(/(\\['"\\])/)
    .filter((part) => part !== '')
    .map((part): Token => ({ text: part, kind: /^\\['"\\]$/.test(part) ? 'number' : 'string', weight: 400 }))
}

function retag(token: Token, kind: TokenKind, weight: Token['weight'] = 400) {
  token.kind = kind
  token.weight = weight
}

function classify(text: string, language: Exclude<Language, 'rule'>, previous: string): Token {
  const token = (kind: TokenKind, weight: Token['weight'] = 400): Token => ({ text, kind, weight })

  if (language === 'plain' || /^\s+$/.test(text)) return token('text')
  if (text.startsWith('//') || text.startsWith('--')) return token('comment')
  if (/^['"]/.test(text)) return token('string')

  switch (language) {
    case 'php':
      if (text.startsWith('$')) return token('variable')
      if (text.startsWith('@')) return token('keyword', 600)
      if (text === '->' || text === '::' || text === '#[') return token('punctuation')
      if (previous === '->' || previous === '::') return token('function')
      if (PHP_KEYWORDS.has(text)) return token('keyword')
      if (/^[A-Z]/.test(text)) return token('class')
      if (/^\d/.test(text)) return token('number')
      if (/^\w+$/.test(text)) return token('function')
      return token('punctuation')

    case 'sql':
      if (SQL_KEYWORDS.has(text.toLowerCase())) return token('keyword', 500)
      if (/^\d/.test(text)) return token('number')
      if (/^\w+$/.test(text)) return token('text')
      return token('punctuation')
  }
}
