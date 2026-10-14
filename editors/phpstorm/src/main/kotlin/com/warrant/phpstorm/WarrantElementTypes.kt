package com.warrant.phpstorm

import com.intellij.psi.tree.IElementType

/** The nodes [WarrantParser] builds above the tokens. */
object WarrantElementTypes {
    /** A `{ ... }`, from its opening brace to its closing one. */
    @JvmField val BLOCK = IElementType("WARRANT_BLOCK", WarrantLanguage)
}
