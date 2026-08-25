package com.warrant.phpstorm

import com.intellij.extapi.psi.ASTWrapperPsiElement
import com.intellij.lang.ASTNode
import com.intellij.lang.ParserDefinition
import com.intellij.lang.PsiParser
import com.intellij.lexer.Lexer
import com.intellij.openapi.project.Project
import com.intellij.psi.FileViewProvider
import com.intellij.psi.PsiElement
import com.intellij.psi.PsiFile
import com.intellij.psi.tree.IElementType
import com.intellij.psi.tree.IFileElementType
import com.intellij.psi.tree.TokenSet

/**
 * Wires the lexer and (trivial) parser together and tells the platform which
 * tokens are comments / strings. Whitespace is recognised automatically because
 * the lexer emits `TokenType.WHITE_SPACE`.
 */
class WarrantParserDefinition : ParserDefinition {
    override fun createLexer(project: Project?): Lexer = WarrantLexer()
    override fun createParser(project: Project?): PsiParser = WarrantParser()
    override fun getFileNodeType(): IFileElementType = FILE
    override fun getCommentTokens(): TokenSet = COMMENTS
    override fun getStringLiteralElements(): TokenSet = STRINGS

    // With a flat parse tree there are no composite elements, but the platform
    // still requires an implementation.
    override fun createElement(node: ASTNode): PsiElement = ASTWrapperPsiElement(node)
    override fun createFile(viewProvider: FileViewProvider): PsiFile = WarrantFile(viewProvider)

    companion object {
        val FILE = IFileElementType(WarrantLanguage)
        val COMMENTS = TokenSet.create(WarrantTokenTypes.COMMENT)
        val STRINGS = TokenSet.create(WarrantTokenTypes.STRING)
    }
}
