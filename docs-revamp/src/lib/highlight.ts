/** `plain` leaves every token uncoloured, for text such as an error message. */
export type Language = 'rule' | 'php' | 'sql' | 'plain'

export type TokenKind =
  | 'keyword'
  | 'ability'
  | 'condition'
  | 'variable'
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

const RULE_KEYWORDS = new Set(['if', 'they', 'can', 'cannot', 'and', 'or', 'not', 'because'])
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
 * A small tokenizer for the languages the site shows. It colours by
 * shape rather than by grammar, which is enough for short, hand-written
 * snippets and keeps the page free of a full highlighter.
 *
 * In rule text, every word after `can` or `cannot` on the same line is an
 * ability; every other bare word is a condition.
 */
export function highlight(source: string, language: Language): Line[] {
  const lines = source.replace(/^\n/, '').replace(/\s+$/, '').split('\n')

  return lines.map((line) => {
    let inAbilityList = false
    let previous = ''

    const tokens = (line.match(TOKEN) ?? []).map((text): Token => {
      const token = classify(text, language, previous, inAbilityList)
      if (language === 'rule' && (text === 'can' || text === 'cannot')) inAbilityList = true
      if (!/^\s+$/.test(text)) previous = text
      return token
    })

    return { tokens }
  })
}

function classify(text: string, language: Language, previous: string, inAbilityList: boolean): Token {
  const token = (kind: TokenKind, weight: Token['weight'] = 400): Token => ({ text, kind, weight })

  if (language === 'plain' || /^\s+$/.test(text)) return token('text')
  if (text.startsWith('//') || text.startsWith('--')) return token('comment')
  if (/^['"]/.test(text)) return token('string')

  switch (language) {
    case 'rule':
      if (RULE_KEYWORDS.has(text)) return token('keyword', 600)
      if (text === '*') return token('ability', 600)
      if (text.startsWith('@')) return token('keyword')
      if (/^\d/.test(text)) return token('number')
      if (/^\w+$/.test(text)) return inAbilityList ? token('ability', 500) : token('condition')
      return token('punctuation')

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
