package com.warrant.phpstorm

/**
 * How the name a condition or template has in rule text and the name of the
 * method that declares it turn into each other.
 *
 * Warrant takes a method's rule-text name from its method name, as Laravel's
 * `Str::snake()` writes it: `isOwner` is `is_owner`. [ruleTextNameOf] does that.
 *
 * Going the other way must be that conversion's exact reverse, and everything
 * that needs a method name for a rule-text name uses [methodNameFor] for it:
 * renaming a condition or template from rule text, which renames its method, and
 * the words given to the word index, which PhpStorm looks a method's name up in
 * before counting its usages. A reverse that is not exact names a method whose
 * rule-text name is another one. Writing each word after the first with a
 * capital is not exact: `group_123` would become `group123`, whose rule-text
 * name is `group123`, since `Str::snake()` puts an underscore only before a
 * capital. The exact reverse keeps that underscore, `group_123`.
 */
object WarrantNames {

    /** A name PHP allows for a method. */
    private val PHP_NAME = Regex("[A-Za-z_\\x80-\\uffff][A-Za-z0-9_\\x80-\\uffff]*")

    /** The rule-text name of the method [methodName], as `Str::snake()` writes a name with no spaces. */
    fun ruleTextNameOf(methodName: String): String = methodName.replace(Regex("(.)(?=[A-Z])"), "$1_").lowercase()

    /**
     * The method name whose rule-text name is [ruleTextName], or null when there
     * is none: when [ruleTextName] holds a character no PHP name can, such as a
     * `-`, or a capital, which a rule-text name taken from a method never has.
     *
     * An underscore with a lowercase letter after it, past the first character,
     * is the capital `Str::snake()` put it before; every other underscore is the
     * method name's own.
     */
    fun methodNameFor(ruleTextName: String): String? {
        val methodName = buildString {
            var i = 0

            while (i < ruleTextName.length) {
                val next = ruleTextName.getOrNull(i + 1)

                if (i > 0 && ruleTextName[i] == '_' && next != null && next in 'a'..'z') {
                    append(next.uppercaseChar())
                    i += 2
                } else {
                    append(ruleTextName[i])
                    i++
                }
            }
        }

        return methodName.takeIf { PHP_NAME.matches(it) && ruleTextNameOf(it) == ruleTextName }
    }

    /** Whether [text] is one name as rule text reads it, and no keyword or constant. */
    fun isName(text: String): Boolean {
        val lexer = WarrantLexer()
        lexer.start(text)

        return text.isNotEmpty() && lexer.tokenType == WarrantTokenTypes.IDENTIFIER && lexer.tokenEnd == text.length
    }
}
