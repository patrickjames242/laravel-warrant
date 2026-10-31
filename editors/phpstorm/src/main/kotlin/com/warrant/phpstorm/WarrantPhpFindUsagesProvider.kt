package com.warrant.phpstorm

import com.intellij.lang.cacheBuilder.WordOccurrence
import com.intellij.lang.cacheBuilder.WordsScanner
import com.intellij.lang.findUsages.FindUsagesProvider
import com.intellij.lang.findUsages.LanguageFindUsages
import com.intellij.psi.PsiElement
import com.intellij.util.Processor
import com.jetbrains.php.lang.PhpLanguage

/**
 * PHP's own find-usages provider, with the words it gives the word index for a
 * PHP file that may hold rule text added to (see [WarrantPhpWordsScanner]).
 * Everything else it hands to PHP's provider unchanged.
 */
class WarrantPhpFindUsagesProvider : FindUsagesProvider {

    /** The PHP plugin's provider, which this one stands in front of. */
    private val php: FindUsagesProvider? by lazy {
        LanguageFindUsages.INSTANCE.allForLanguage(PhpLanguage.INSTANCE).firstOrNull { it !is WarrantPhpFindUsagesProvider }
    }

    override fun getWordsScanner(): WordsScanner? = php?.wordsScanner?.let(::WarrantPhpWordsScanner)

    override fun canFindUsagesFor(element: PsiElement): Boolean = php?.canFindUsagesFor(element) ?: false

    override fun getHelpId(element: PsiElement): String? = php?.getHelpId(element)

    override fun getType(element: PsiElement): String = php?.getType(element) ?: ""

    override fun getDescriptiveName(element: PsiElement): String = php?.getDescriptiveName(element) ?: ""

    override fun getNodeText(element: PsiElement, useFullName: Boolean): String = php?.getNodeText(element, useFullName) ?: ""
}

/**
 * The words PHP's scanner finds in a PHP file and, when the file may hold rule
 * text, beside each snake case word the name of the method a condition or
 * template by that name would be declared by ([WarrantNames.methodNameFor]), as
 * `isOwner` for `is_owner`.
 *
 * Before counting a method's usages, PhpStorm looks its name up in the word
 * index and counts only in the method's own file when no other file writes it.
 * Rule text in another PHP file never writes the method's name, so without the
 * camel case word that rule text would go uncounted. A snake case word in such a
 * file that names no condition costs only a wider search for a method of that
 * name.
 */
class WarrantPhpWordsScanner(private val php: WordsScanner) : WordsScanner {

    override fun processWords(fileText: CharSequence, processor: Processor<in WordOccurrence>) {
        if (!MAY_HOLD_RULE_TEXT.containsMatchIn(fileText)) {
            php.processWords(fileText, processor)
            return
        }

        php.processWords(fileText) { occurrence ->
            val word = occurrence.baseText.subSequence(occurrence.start, occurrence.end).toString()
            val kind = occurrence.kind

            if (!processor.process(occurrence)) return@processWords false

            val method = if ('_' in word) WarrantNames.methodNameFor(word) else null

            method == null || method == word || processor.process(WordOccurrence(method, 0, method.length, kind))
        }
    }

    private companion object {
        /**
         * Whether a PHP file may hold rule text: it writes a name every way of
         * holding rule text needs, as the language server's
         * `FragmentExtractor::mayHoldRuleText()` reads it.
         */
        val MAY_HOLD_RULE_TEXT = Regex("warrant|boundsyntax|ifraw|ifnotraw|derivedcondition|ruletemplate|function\\s+rules\\b", RegexOption.IGNORE_CASE)
    }
}
