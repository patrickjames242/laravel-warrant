package com.warrant.phpstorm

import com.intellij.openapi.fileTypes.LanguageFileType
import javax.swing.Icon

/**
 * Associates the `.warrant` extension with [WarrantLanguage], so standalone
 * `.warrant` files are highlighted (heredoc injection is handled separately by
 * [WarrantInjector]).
 */
class WarrantFileType private constructor() : LanguageFileType(WarrantLanguage) {
    override fun getName(): String = "Warrant"
    override fun getDescription(): String = "Warrant authorization rule language"
    override fun getDefaultExtension(): String = "warrant"

    // No custom icon yet; drop an SVG in resources and load it here later.
    override fun getIcon(): Icon? = null

    companion object {
        @JvmField
        val INSTANCE = WarrantFileType()
    }
}
