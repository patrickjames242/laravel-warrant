package com.warrant.phpstorm

import com.intellij.formatting.service.FormattingServiceUtil
import com.intellij.testFramework.fixtures.BasePlatformTestCase
import com.warrant.phpstorm.lsp.WarrantFormattingService

/**
 * Reformat Code on a `.warrant` file goes to [WarrantFormattingService], which
 * formats before a save writes the file, whether it reformats the whole file
 * or part of it.
 */
class WarrantFormattingServiceTest : BasePlatformTestCase() {

    fun testFormatsAWarrantFileWithTheWarrantService() {
        val file = myFixture.configureByText("rules.warrant", "if a they can view")

        assertInstanceOf(FormattingServiceUtil.findService(file, true, true), WarrantFormattingService::class.java)
        assertInstanceOf(FormattingServiceUtil.findService(file, true, false), WarrantFormattingService::class.java)
    }
}
