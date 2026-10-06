package com.warrant.phpstorm

import com.intellij.lang.injection.MultiHostInjector
import com.intellij.lang.injection.MultiHostRegistrar
import com.intellij.psi.ElementManipulators
import com.intellij.psi.PsiElement
import com.intellij.psi.util.PsiTreeUtil
import com.jetbrains.php.lang.lexer.PhpTokenTypes
import com.jetbrains.php.lang.parser.PhpElementTypes
import com.jetbrains.php.lang.psi.elements.ArrayCreationExpression
import com.jetbrains.php.lang.psi.elements.ArrayHashElement
import com.jetbrains.php.lang.psi.elements.ClassReference
import com.jetbrains.php.lang.psi.elements.Function
import com.jetbrains.php.lang.psi.elements.FunctionReference
import com.jetbrains.php.lang.psi.elements.Method
import com.jetbrains.php.lang.psi.elements.MethodReference
import com.jetbrains.php.lang.psi.elements.ParameterList
import com.jetbrains.php.lang.psi.elements.PhpClass
import com.jetbrains.php.lang.psi.elements.PhpReturn
import com.jetbrains.php.lang.psi.elements.StringLiteralExpression

/**
 * Injects [WarrantLanguage] into the PHP strings that hold Warrant rule text.
 * This is the PhpStorm counterpart of the VSCode injection grammar in
 * editors/vscode/syntaxes/warrant.php-injection.json.
 *
 * A string literal, heredoc or nowdoc is rule text when it is:
 *
 *   - a heredoc/nowdoc labelled WARRANT, wherever it stands;
 *   - the first argument of `warrant()`, of `Warrant::parse()`,
 *     `WarrantSyntax::parse()` or `WarrantParser::parse()`, or of a builder's
 *     `->ifRaw()` / `->orIfRaw()`, passed by position or by its parameter name;
 *   - returned, alone or inside a returned array, from a method marked
 *     `#[DerivedCondition]` or `#[RuleTemplate]`, or from `rules()` on a
 *     `WarrantSchema` or a `RuleProvider`.
 *
 * The injected range is the string's *content* (the platform gets it from the
 * PHP host's ElementManipulator), so the quotes and heredoc labels stay PHP.
 */
class WarrantInjector : MultiHostInjector {

    override fun getLanguagesToInject(registrar: MultiHostRegistrar, context: PsiElement) {
        if (context !is StringLiteralExpression) return
        // StringLiteralExpression is itself a PsiLanguageInjectionHost.
        if (!context.isValidHost) return

        if (!isRuleText(context)) return

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

        /** Classes whose static `parse()` takes rule text as its first argument. */
        private val PARSE_CLASSES = setOf(
            "\\Warrant\\Facades\\Warrant",
            "\\Warrant\\DSL\\Parsing\\ASTNodes\\WarrantSyntax",
            "\\Warrant\\DSL\\Parsing\\WarrantParser",
            // The facade registered as a global alias.
            "\\Warrant",
        )

        /** Builder methods whose first argument is a condition expression. */
        private val RAW_METHODS = setOf("ifRaw", "orIfRaw")

        /** The rule-text parameter's name, for a call that passes it by name. */
        private val RULE_TEXT_PARAMETERS = setOf("syntax", "source", "expression")

        /** Attributes on a schema method whose returned string is rule text. */
        private val RULE_TEXT_ATTRIBUTES = setOf(
            "\\Warrant\\Schema\\DerivedCondition",
            "\\Warrant\\Schema\\RuleTemplate",
        )

        /** Types whose `rules()` returns rule text. */
        private val RULE_PROVIDERS = setOf(
            "\\Warrant\\Schema\\WarrantSchema",
            "\\Warrant\\Rules\\RuleProvider",
        )

        // Matches the opener of a heredoc `<<<WARRANT` or nowdoc `<<<'WARRANT'`
        // and captures the label. Returns null for ordinary quoted strings.
        private val HEREDOC_OPENER = Regex("""^<<<[ \t]*["']?([A-Za-z_][A-Za-z0-9_]*)["']?""")

        fun heredocLabel(hostText: String): String? =
            HEREDOC_OPENER.find(hostText)?.groupValues?.get(1)

        private fun isRuleText(literal: StringLiteralExpression): Boolean =
            heredocLabel(literal.text) == LABEL
                || isRuleTextArgument(literal)
                || isReturnedRuleText(literal)

        private fun isRuleTextArgument(literal: StringLiteralExpression): Boolean {
            val arguments = literal.parent as? ParameterList ?: return false
            val call = arguments.parent as? FunctionReference ?: return false

            val name = argumentName(literal)
            if (name != null) {
                if (name !in RULE_TEXT_PARAMETERS) return false
            } else if (arguments.parameters.firstOrNull() !== literal) {
                return false
            }

            return when {
                call !is MethodReference -> call.name == "warrant"
                call.isStatic -> call.name == "parse" && isParseClass(call.classReference)
                else -> call.name in RAW_METHODS
            }
        }

        /** The name a named argument is passed by, or null for a positional one. */
        private fun argumentName(argument: PsiElement): String? {
            val colon = PsiTreeUtil.skipWhitespacesAndCommentsBackward(argument) ?: return null
            if (colon.node.elementType != PhpTokenTypes.opCOLON) return null

            return PsiTreeUtil.skipWhitespacesAndCommentsBackward(colon)?.text
        }

        private fun isParseClass(reference: PsiElement?): Boolean =
            reference is ClassReference && reference.fqn in PARSE_CLASSES

        private fun isReturnedRuleText(literal: StringLiteralExpression): Boolean {
            var element: PsiElement = literal.parent ?: return false

            // Climb out of a returned array, however deeply nested.
            while (element is ArrayCreationExpression
                || element is ArrayHashElement
                || element.node.elementType == PhpElementTypes.ARRAY_VALUE
            ) {
                element = element.parent ?: return false
            }

            if (element !is PhpReturn) return false

            // The nearest function, so a string returned from a closure inside
            // the method is not taken for the method's own answer.
            val method = PsiTreeUtil.getParentOfType(element, Function::class.java) as? Method ?: return false

            if (method.attributes.any { it.fqn in RULE_TEXT_ATTRIBUTES }) return true

            val owner = method.containingClass ?: return false

            return method.name == "rules" && isRuleProvider(owner, mutableSetOf())
        }

        private fun isRuleProvider(phpClass: PhpClass, seen: MutableSet<String>): Boolean {
            if (!seen.add(phpClass.fqn)) return false
            if (phpClass.fqn in RULE_PROVIDERS) return true

            val supers = listOfNotNull(phpClass.superClass) + phpClass.implementedInterfaces

            return supers.any { isRuleProvider(it, seen) }
        }
    }
}
