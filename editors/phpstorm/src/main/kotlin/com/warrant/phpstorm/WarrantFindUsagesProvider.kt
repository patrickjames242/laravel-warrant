package com.warrant.phpstorm

import com.intellij.lang.cacheBuilder.WordOccurrence
import com.intellij.lang.cacheBuilder.WordsScanner
import com.intellij.lang.findUsages.FindUsagesProvider
import com.intellij.psi.PsiElement
import com.intellij.util.Processor

/**
 * Says which words a `.warrant` file writes, for the index PhpStorm looks words
 * up in. Nothing in Warrant text is searched for usages from here: a name's
 * usages are what it names, which the PHP declares.
 */
class WarrantFindUsagesProvider : FindUsagesProvider {

    override fun getWordsScanner(): WordsScanner = WarrantWordsScanner()

    override fun canFindUsagesFor(element: PsiElement): Boolean = false

    override fun getHelpId(element: PsiElement): String? = null

    override fun getType(element: PsiElement): String = ""

    override fun getDescriptiveName(element: PsiElement): String = ""

    override fun getNodeText(element: PsiElement, useFullName: Boolean): String = ""
}

/**
 * The words of Warrant text: each name it writes, and the method name in camel
 * case that a condition or template name in snake case is taken from, as
 * `isOwner` for `is_owner`.
 *
 * Before searching a method's usages across the project, PhpStorm looks its
 * name up in the word index, and searches only the method's own file when no
 * other file writes it. Rule text never writes a method's name, only the name
 * taken from it, so without the camel case word the search would never reach a
 * `.warrant` file.
 */
class WarrantWordsScanner : WordsScanner {

    override fun processWords(fileText: CharSequence, processor: Processor<in WordOccurrence>) {
        val lexer = WarrantLexer()
        lexer.start(fileText)

        while (lexer.tokenType != null) {
            if (lexer.tokenType == WarrantTokenTypes.IDENTIFIER) {
                val name = lexer.tokenSequence.toString()
                val method = camelCase(name)

                if (!processor.process(WordOccurrence(fileText, lexer.tokenStart, lexer.tokenEnd, WordOccurrence.Kind.CODE))) return
                if (method != name && !processor.process(WordOccurrence(method, 0, method.length, WordOccurrence.Kind.CODE))) return
            }

            lexer.advance()
        }
    }

    companion object {
        /** [name] in camel case, as Laravel's `Str::camel()` writes it. */
        fun camelCase(name: String): String =
            name.split('_', '-')
                .filter { it.isNotEmpty() }
                .mapIndexed { i, word -> if (i == 0) word.replaceFirstChar { it.lowercaseChar() } else word.replaceFirstChar { it.uppercaseChar() } }
                .joinToString("")
    }
}
