package com.warrant.phpstorm

import com.intellij.lang.injection.MultiHostInjector
import com.intellij.lang.injection.MultiHostRegistrar
import com.intellij.psi.ElementManipulators
import com.intellij.psi.PsiElement
import com.intellij.psi.PsiLanguageInjectionHost
import com.jetbrains.php.lang.psi.elements.StringLiteralExpression

/**
 * Injects [WarrantLanguage] into PHP heredocs/nowdocs whose label is WARRANT
 * (or DSL). This is the PhpStorm counterpart of the VSCode injection grammar in
 * editors/vscode/syntaxes/warrant.php-injection.json.
 *
 * Once injected, PhpStorm treats the heredoc body as Warrant: the lexer +
 * highlighter run on it, and later the language server will attach to it too.
 *
 * We inject the *content* range (the platform gets it from the PHP host's
 * ElementManipulator), so the `<<<WARRANT` opener and closing label stay PHP.
 */
class WarrantInjector : MultiHostInjector {

    override fun getLanguagesToInject(registrar: MultiHostRegistrar, context: PsiElement) {
        if (context !is StringLiteralExpression) return
        // StringLiteralExpression is itself a PsiLanguageInjectionHost.
        if (!context.isValidHost) return

        val label = heredocLabel(context.getText()) ?: return
        if (label != LABEL) return

        val contentRange = ElementManipulators.getManipulator(context).getRangeInElement(context)

        registrar.startInjecting(WarrantLanguage)
            .addPlace(null, null, context, contentRange)
            .doneInjecting()
    }

    override fun elementsToInjectIn(): List<Class<out PsiElement>> =
        listOf(StringLiteralExpression::class.java)

    companion object {
        /** Heredoc label that holds Warrant source: `<<<'WARRANT' ... WARRANT`. */
        private const val LABEL = "WARRANT"

        // Matches the opener of a heredoc `<<<WARRANT` or nowdoc `<<<'WARRANT'`
        // and captures the label. Returns null for ordinary quoted strings.
        private val HEREDOC_OPENER = Regex("""^<<<[ \t]*["']?([A-Za-z_][A-Za-z0-9_]*)["']?""")

        fun heredocLabel(hostText: String): String? =
            HEREDOC_OPENER.find(hostText)?.groupValues?.get(1)
    }
}
