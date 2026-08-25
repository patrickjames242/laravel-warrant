package com.warrant.phpstorm

import com.intellij.lang.ASTNode
import com.intellij.lang.PsiBuilder
import com.intellij.lang.PsiParser
import com.intellij.psi.tree.IElementType

/**
 * Deliberately trivial parser: it consumes every token into one flat node under
 * the file root, producing no grammar structure.
 *
 * That is all injection needs — the platform only requires *some* ParserDefinition
 * to build a PSI file for the injected fragment. Highlighting comes entirely from
 * the lexer + [WarrantSyntaxHighlighter]; this parser adds nothing visible.
 *
 * When the language server lands, error-checking and completion come from it, so
 * this can stay flat indefinitely. Only replace it if you decide to build native
 * IntelliJ smart-features instead of using the server.
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
