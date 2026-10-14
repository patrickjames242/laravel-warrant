package com.warrant.phpstorm

import com.intellij.openapi.fileTypes.LanguageFileType
import com.intellij.openapi.util.IconLoader
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

    override fun getIcon(): Icon = ICON

    companion object {
        @JvmField
        val INSTANCE = WarrantFileType()

        /**
         * A rubber stamp's ring with a W. The IDE picks `warrant_dark.svg`, a
         * lighter red, on a dark theme.
         */
        private val ICON: Icon = IconLoader.getIcon("/icons/warrant.svg", WarrantFileType::class.java)
    }
}
