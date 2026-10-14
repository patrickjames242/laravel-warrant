package com.warrant.phpstorm

import com.intellij.formatting.Block
import com.intellij.formatting.ChildAttributes
import com.intellij.formatting.FormattingContext
import com.intellij.formatting.FormattingModel
import com.intellij.formatting.FormattingModelBuilder
import com.intellij.formatting.FormattingModelProvider
import com.intellij.formatting.Indent
import com.intellij.formatting.Spacing
import com.intellij.lang.ASTNode
import com.intellij.psi.TokenType
import com.intellij.psi.formatter.common.AbstractBlock

/**
 * Indents what is inside a `{ ... }` one level deeper than its braces, and
 * nothing else: spacing within a line is left as it is written.
 *
 * The IDE asks this where a new line goes, so Enter after a `{` indents the line
 * it opens, and Enter between `{` and `}` opens an indented line with the `}`
 * on the line below. Reformat Code re-indents lines the same way.
 *
 * Inside PHP the IDE asks PHP where a new line goes, not this; there
 * [WarrantEnterHandler] indents it.
 */
class WarrantFormattingModelBuilder : FormattingModelBuilder {
    override fun createModel(formattingContext: FormattingContext): FormattingModel =
        FormattingModelProvider.createFormattingModelForPsiFile(
            formattingContext.containingFile,
            WarrantBlock(formattingContext.node, Indent.getNoneIndent()),
            formattingContext.codeStyleSettings,
        )
}

/**
 * A node of the Warrant tree, as the formatter sees it.
 *
 * @param indent The node's own indent, relative to its parent.
 */
private class WarrantBlock(node: ASTNode, private val indent: Indent) :
    AbstractBlock(node, null, null) {

    private val isBlock = node.elementType == WarrantElementTypes.BLOCK

    override fun buildChildren(): List<Block> {
        val children = mutableListOf<Block>()
        var child = myNode.firstChildNode

        while (child != null) {
            if (child.elementType != TokenType.WHITE_SPACE && child.textLength > 0) {
                children.add(WarrantBlock(child, if (isBlock && !isBrace(child)) Indent.getNormalIndent() else Indent.getNoneIndent()))
            }
            child = child.treeNext
        }

        return children
    }

    override fun getIndent(): Indent = indent

    override fun getSpacing(child1: Block?, child2: Block): Spacing? = null

    override fun isLeaf(): Boolean = myNode.firstChildNode == null

    /**
     * Where a line typed at $newChildIndex goes: one level in when it is inside
     * a block, before its closing brace.
     */
    override fun getChildAttributes(newChildIndex: Int): ChildAttributes {
        val closed = subBlocks.lastOrNull()?.let { (it as WarrantBlock).node.elementType == WarrantTokenTypes.RBRACE } == true
        val insideBlock = isBlock && (newChildIndex < subBlocks.size || !closed)

        return ChildAttributes(if (insideBlock) Indent.getNormalIndent() else Indent.getNoneIndent(), null)
    }

    private fun isBrace(node: ASTNode): Boolean =
        node.elementType == WarrantTokenTypes.LBRACE || node.elementType == WarrantTokenTypes.RBRACE
}
