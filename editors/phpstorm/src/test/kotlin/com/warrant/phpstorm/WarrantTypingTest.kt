package com.warrant.phpstorm

import com.intellij.testFramework.fixtures.BasePlatformTestCase

/**
 * What typing does in Warrant: brackets and quotes close as they are opened, are
 * stepped over as they are closed, and Comment Line uses `#`. Each is checked in
 * a `.warrant` file and in Warrant injected into a PHP heredoc.
 */
class WarrantTypingTest : BasePlatformTestCase() {

    fun testClosesAParenthesis() {
        typeIn("a.warrant", "if can<caret>", "(", "if can(<caret>)")
    }

    fun testStepsOverAClosingParenthesis() {
        typeIn("a.warrant", "if can(view<caret>)", ")", "if can(view)<caret>")
    }

    fun testClosesABrace() {
        typeIn("a.warrant", "for documents <caret>", "{", "for documents {<caret>}")
    }

    fun testClosesAQuote() {
        typeIn("a.warrant", "they cannot view because <caret>", "'", "they cannot view because '<caret>'")
    }

    fun testClosesAQuoteBeforeALaterMessage() {
        for (quote in listOf("'", "\"")) {
            typeIn(
                "a.warrant",
                "they cannot view because <caret>\nif b they cannot edit because 'no'",
                quote,
                "they cannot view because $quote<caret>$quote\nif b they cannot edit because 'no'",
            )
        }
    }

    fun testClosesAQuoteBeforeALaterMessageInPhp() {
        for (quote in listOf("'", "\"")) {
            typeIn(
                "a.php",
                heredoc("they cannot view because <caret>\n    if b they cannot edit because 'no'"),
                quote,
                heredoc("they cannot view because $quote<caret>$quote\n    if b they cannot edit because 'no'"),
            )
        }
    }

    fun testStepsOverAClosingQuote() {
        typeIn("a.warrant", "they cannot view because 'no<caret>'", "'", "they cannot view because 'no'<caret>")
    }

    fun testLeavesAQuoteInsideAString() {
        typeIn("a.warrant", "they cannot view because 'it<caret> is'", "'", "they cannot view because 'it'<caret> is'")
    }

    fun testClosesAParenthesisInPhp() {
        typeIn("a.php", heredoc("if can<caret>"), "(", heredoc("if can(<caret>)"))
    }

    fun testClosesABraceInPhp() {
        typeIn("a.php", heredoc("for documents <caret>"), "{", heredoc("for documents {<caret>}"))
    }

    fun testClosesAQuoteInPhp() {
        typeIn("a.php", heredoc("they cannot view because <caret>"), "'", heredoc("they cannot view because '<caret>'"))
    }

    fun testIndentsTheLineEnterOpensBetweenBraces() {
        typeIn("a.warrant", "for documents {<caret>}", "\n", "for documents {\n    <caret>\n}")
    }

    fun testIndentsTheLineEnterOpensAfterABrace() {
        typeIn(
            "a.warrant",
            "for documents {\n    can they edit {<caret>\n    }\n}",
            "\n",
            "for documents {\n    can they edit {\n        <caret>\n    }\n}",
        )
    }

    fun testOpensAnIndentedBlockAsABraceAndEnterAreTyped() {
        typeIn("a.warrant", "for documents <caret>", "{\n", "for documents {\n    <caret>\n}")
    }

    fun testKeepsTheIndentOfALineEnterOpensInsideABlock() {
        typeIn("a.warrant", "for documents {\n    they can view<caret>\n}", "\n", "for documents {\n    they can view\n    <caret>\n}")
    }

    fun testLeavesALineEnterOpensAfterABlockUnindented() {
        typeIn("a.warrant", "for documents {\n    they can view\n}<caret>", "\n", "for documents {\n    they can view\n}\n<caret>")
    }

    fun testIndentsTheLineEnterOpensBetweenBracesInPhp() {
        typeIn("a.php", heredoc("for documents {<caret>}"), "\n", heredoc("for documents {\n        <caret>\n    }"))
    }

    fun testIndentsTheLineEnterOpensAfterABraceInPhp() {
        typeIn(
            "a.php",
            heredoc("for documents {\n        can they edit {<caret>\n        }\n    }"),
            "\n",
            heredoc("for documents {\n        can they edit {\n            <caret>\n        }\n    }"),
        )
    }

    fun testOpensAnIndentedBlockAsABraceAndEnterAreTypedInPhp() {
        typeIn("a.php", heredoc("for documents <caret>"), "{\n", heredoc("for documents {\n        <caret>\n    }"))
    }

    fun testIndentsTheLineEnterOpensInAStringARuleTemplateReturns() {
        typeIn("a.php", ruleTemplate("for documents {<caret>}"), "\n", ruleTemplate("for documents {\n                <caret>\n            }"))
    }

    fun testIgnoresBracesInAStringInPhp() {
        typeIn(
            "a.php",
            heredoc("they cannot view because '{'<caret>"),
            "\n",
            heredoc("they cannot view because '{'\n    <caret>"),
        )
    }

    fun testCommentsALineOutWithAHash() {
        myFixture.configureByText("a.warrant", "if a they <caret>can view")
        myFixture.performEditorAction("CommentByLineComment")
        myFixture.checkResult("# if a they can view")
    }

    /** Types $typed into a file named $name holding $before, and checks it then holds $after. */
    private fun typeIn(name: String, before: String, typed: String, after: String) {
        myFixture.configureByText(name, before)
        myFixture.type(typed)
        myFixture.checkResult(after)
    }

    /** A PHP schema whose #[RuleTemplate] method returns a multi-line string holding the line $rule. */
    private fun ruleTemplate(rule: String): String =
        "<?php\nuse Warrant\\Schema\\RuleTemplate;\n\nclass S\n{\n    #[RuleTemplate]\n    public function t(): string\n    {\n        return \"\n            $rule\n        \";\n    }\n}\n"

    /** A PHP file whose WARRANT heredoc holds the line $rule. */
    private fun heredoc(rule: String): String = "<?php\n\$rules = <<<'WARRANT'\n    $rule\n    WARRANT;\n"
}
