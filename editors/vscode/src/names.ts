/**
 * How the name a condition or template has in rule text and the name of the
 * method that declares it turn into each other, and which names rule text can
 * write.
 *
 * Warrant takes a method's rule-text name from its method name, as Laravel's
 * `Str::snake()` writes it: `isOwner` is `is_owner`. Going the other way is that
 * conversion's exact reverse, so `group_123` stays `group_123`: `group123`
 * would be `group123` in rule text, since `Str::snake()` puts an underscore
 * only before a capital.
 */

/** Mirror of the lexer's keywords and constants, which a name cannot be. */
const RESERVED = new Set([
  'if', 'they', 'can', 'cannot', 'because', 'check', 'and', 'or', 'not',
  'for', 'with', 'as', 'its', 'any', 'all', 'of', 'when', 'otherwise',
  'true', 'false', 'null',
])

/** One name as the lexer reads it: a letter or underscore, then letters, digits, `_` and `-`. */
const NAME = /^[A-Za-z_][A-Za-z0-9_-]*$/

/** A name PHP allows for a method. */
const PHP_NAME = /^[A-Za-z_\x80-￿][A-Za-z0-9_\x80-￿]*$/

/** Whether `text` is one name as rule text reads it, and no keyword or constant. */
export function isName(text: string): boolean {
  return NAME.test(text) && !RESERVED.has(text)
}

/** The rule-text name of the method `methodName`, as `Str::snake()` writes a name with no spaces. */
export function ruleTextNameOf(methodName: string): string {
  return methodName.replace(/(.)(?=[A-Z])/g, '$1_').toLowerCase()
}

/**
 * The method name whose rule-text name is `ruleTextName`, or undefined when
 * there is none. An underscore with a lowercase letter after it, past the first
 * character, is the capital `Str::snake()` put it before; every other
 * underscore is the method name's own.
 */
export function methodNameFor(ruleTextName: string): string | undefined {
  const methodName = ruleTextName.replace(/(?<=.)_([a-z])/g, (_, letter: string) => letter.toUpperCase())

  return PHP_NAME.test(methodName) && ruleTextNameOf(methodName) === ruleTextName ? methodName : undefined
}
