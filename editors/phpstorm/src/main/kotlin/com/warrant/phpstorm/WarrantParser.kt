package com.warrant.phpstorm

import com.intellij.lang.ASTNode
import com.intellij.lang.PsiBuilder
import com.intellij.lang.PsiParser
import com.intellij.psi.tree.IElementType

/**
 * A flat parser: every token sits directly under the file root. Highlighting
 * and injection need nothing more, error-checking and completion come from the
 * language server, and formatting from the Warrant formatter.
 */
class WarrantParser : PsiParser {
    override fun parse(root: IElementType, builder: PsiBuilder): ASTNode {
        val rootMarker = builder.mark()

        while (!builder.eof()) {
            builder.advanceLexer()
        }

        rootMarker.done(root)
        return builder.treeBuilt
    }
}
