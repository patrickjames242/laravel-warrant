package com.warrant.phpstorm

import com.intellij.openapi.editor.colors.TextAttributesKey
import com.intellij.testFramework.fixtures.BasePlatformTestCase

/**
 * The escapes coloured in a Warrant string: `\'`, `\"` and `\\` as valid, any
 * other backslash pair as invalid, in a `.warrant` file and inside PHP.
 */
class WarrantEscapeTest : BasePlatformTestCase() {

    fun testColoursEachValidEscape() {
        myFixture.configureByText("a.warrant", """they cannot view because 'don\'t say \"no\" or \\'""")

        assertEquals(listOf("\\'", "\\\"", "\\\"", "\\\\"), escapes(WarrantSyntaxHighlighter.VALID_ESCAPE))
        assertEquals(emptyList<String>(), escapes(WarrantSyntaxHighlighter.INVALID_ESCAPE))
    }

    fun testColoursAnInvalidEscape() {
        myFixture.configureByText("a.warrant", """they cannot view because 'a\nb'""")

        assertEquals(listOf("\\n"), escapes(WarrantSyntaxHighlighter.INVALID_ESCAPE))
    }

    fun testColoursNothingOutsideAString() {
        myFixture.configureByText("a.warrant", "# don\\'t\nif a they can view")

        assertEquals(emptyList<String>(), escapes(WarrantSyntaxHighlighter.VALID_ESCAPE))
    }

    fun testColoursAnEscapeInANowdoc() {
        myFixture.configureByText("a.php", "<?php\n\$r = <<<'WARRANT'\n    they cannot view because 'don\\'t'\n    WARRANT;\n")

        assertEquals(listOf("\\'"), escapes(WarrantSyntaxHighlighter.VALID_ESCAPE))
    }

    fun testColoursAnEscapeInADoubleQuotedStringRulesReturns() {
        // The schema base class, which rules() must be on to hold rule text.
        myFixture.addFileToProject("WarrantSchema.php", "<?php\nnamespace Warrant\\Schema;\n\nabstract class WarrantSchema {}\n")
        myFixture.configureByText(
            "a.php",
            "<?php\nclass S extends \\Warrant\\Schema\\WarrantSchema\n{\n    public function rules(): string\n    {\n        return \"\n            they cannot view because 'don\\'t'\n        \";\n    }\n}\n",
        )

        assertEquals(listOf("\\'"), escapes(WarrantSyntaxHighlighter.VALID_ESCAPE))
    }

    /** The text of each range highlighted with $key, in the order they appear. */
    private fun escapes(key: TextAttributesKey): List<String> =
        myFixture.doHighlighting()
            .filter { it.forcedTextAttributesKey == key }
            .sortedBy { it.startOffset }
            .map { myFixture.editor.document.text.substring(it.startOffset, it.endOffset) }
}
