package com.warrant.phpstorm

import com.intellij.lang.BracePair
import com.intellij.lang.PairedBraceMatcher
import com.intellij.psi.PsiFile
import com.intellij.psi.tree.IElementType

/**
 * The bracket pairs of Warrant: `( )` around arguments and conditions, and
 * `{ }` around a block of rules. The IDE uses them to close a bracket as the
 * opening one is typed, to highlight the matching bracket at the caret, and to
 * jump between the two. The same applies inside PHP, where Warrant is injected.
 */
class WarrantBraceMatcher : PairedBraceMatcher {

    override fun getPairs(): Array<BracePair> = PAIRS

    override fun isPairedBracesAllowedBeforeType(lbraceType: IElementType, contextType: IElementType?): Boolean = true

    override fun getCodeConstructStart(file: PsiFile?, openingBraceOffset: Int): Int = openingBraceOffset

    companion object {
        private val PAIRS = arrayOf(
            BracePair(WarrantTokenTypes.LPAREN, WarrantTokenTypes.RPAREN, false),
            BracePair(WarrantTokenTypes.LBRACE, WarrantTokenTypes.RBRACE, true),
        )
    }
}
