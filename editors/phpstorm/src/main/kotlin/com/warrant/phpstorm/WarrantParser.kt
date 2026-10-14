package com.warrant.phpstorm

import com.intellij.lang.ASTNode
import com.intellij.lang.PsiBuilder
import com.intellij.lang.PsiParser
import com.intellij.psi.tree.IElementType

/**
 * A nearly flat parser: every `{ ... }` becomes a [WarrantElementTypes.BLOCK]
 * node, braces included, and every other token sits directly under it or under
 * the file root.
 *
 * Blocks are the one structure the IDE needs, because [WarrantFormattingModelBuilder]
 * indents a block's contents, which is what makes Enter indent the line it
 * opens. Error-checking and completion come from the language server, so the
 * parser builds nothing else. An unmatched `}` is left as a token, and a block
 * without its `}` runs to the end of the text.
 */
class WarrantParser : PsiParser {
    override fun parse(root: IElementType, builder: PsiBuilder): ASTNode {
        val rootMarker = builder.mark()
        val blocks = ArrayDeque<PsiBuilder.Marker>()

        while (!builder.eof()) {
            when (builder.tokenType) {
                WarrantTokenTypes.LBRACE -> {
                    blocks.addLast(builder.mark())
                    builder.advanceLexer()
                }
                WarrantTokenTypes.RBRACE -> {
                    builder.advanceLexer()
                    blocks.removeLastOrNull()?.done(WarrantElementTypes.BLOCK)
                }
                else -> builder.advanceLexer()
            }
        }

        while (blocks.isNotEmpty()) {
            blocks.removeLast().done(WarrantElementTypes.BLOCK)
        }

        rootMarker.done(root)
        return builder.treeBuilt
    }
}
