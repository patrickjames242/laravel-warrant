package com.warrant.phpstorm

import com.intellij.lang.Language

/**
 * The Warrant DSL as a first-class IntelliJ language. Every other piece of the
 * plugin (file type, lexer, parser, highlighter, injector) references this
 * singleton. The id "Warrant" must match the `language=` attributes in
 * plugin.xml.
 */
object WarrantLanguage : Language("Warrant") {
    private fun readResolve(): Any = WarrantLanguage
    override fun getDisplayName(): String = "Warrant"
}
