package com.warrant.phpstorm

import com.intellij.lang.annotation.AnnotationHolder
import com.intellij.lang.annotation.Annotator
import com.intellij.lang.annotation.HighlightSeverity
import com.intellij.openapi.util.TextRange
import com.intellij.psi.PsiElement

/**
 * Colours the escapes in a Warrant string literal, as Lexer.php reads them:
 * `\'`, `\"` and `\\` are escapes, and a backslash before anything else is an
 * invalid escape, which Lexer.php reports as an error.
 *
 * The lexer keeps a string as one token, so the quote handler sees whole
 * strings; the escapes are coloured over it here instead.
 */
class WarrantEscapeAnnotator : Annotator {

    override fun annotate(element: PsiElement, holder: AnnotationHolder) {
        if (element.node.elementType != WarrantTokenTypes.STRING) return

        val text = element.text
        val start = element.textRange.startOffset
        val quote = text.firstOrNull() ?: return
        var i = 1

        while (i < text.length) {
            val c = text[i]
            if (c == quote && i == text.length - 1) break
            if (c == '\\' && i + 1 < text.length) {
                val key = if (text[i + 1] in VALID_ESCAPES) WarrantSyntaxHighlighter.VALID_ESCAPE else WarrantSyntaxHighlighter.INVALID_ESCAPE
                holder.newSilentAnnotation(HighlightSeverity.INFORMATION)
                    .range(TextRange(start + i, start + i + 2))
                    .textAttributes(key)
                    .create()
                i += 2
                continue
            }
            i++
        }
    }

    companion object {
        /** What may follow a backslash in a Warrant string. */
        private val VALID_ESCAPES = setOf('\'', '"', '\\')
    }
}
