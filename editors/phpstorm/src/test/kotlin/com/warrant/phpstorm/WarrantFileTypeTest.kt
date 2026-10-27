package com.warrant.phpstorm

import com.intellij.ide.fileTemplates.FileTemplateManager
import com.intellij.testFramework.fixtures.BasePlatformTestCase
import java.util.Properties

/** A `.warrant` file is a Warrant file, shown with the Warrant icon. */
class WarrantFileTypeTest : BasePlatformTestCase() {

    fun testGivesAWarrantFileTheWarrantIcon() {
        val file = myFixture.configureByText("rules.warrant", "they can view")

        assertSame(WarrantFileType.INSTANCE, file.fileType)
        assertEquals(16, file.fileType.icon!!.iconWidth)
        assertEquals(16, file.fileType.icon!!.iconHeight)
    }

    fun testCreatesANewWarrantFileEmpty() {
        val template = FileTemplateManager.getInstance(project).getInternalTemplate(CreateWarrantFileAction.TEMPLATE)
        val properties = Properties().apply { setProperty("NAME", "documents") }

        assertEquals("warrant", template.extension)
        assertEquals("", template.getText(properties))
    }
}
