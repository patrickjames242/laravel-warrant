package com.warrant.phpstorm

import com.intellij.testFramework.fixtures.BasePlatformTestCase

/** A `.warrant` file is a Warrant file, shown with the Warrant icon. */
class WarrantFileTypeTest : BasePlatformTestCase() {

    fun testGivesAWarrantFileTheWarrantIcon() {
        val file = myFixture.configureByText("rules.warrant", "they can view")

        assertSame(WarrantFileType.INSTANCE, file.fileType)
        assertEquals(16, file.fileType.icon!!.iconWidth)
        assertEquals(16, file.fileType.icon!!.iconHeight)
    }
}
