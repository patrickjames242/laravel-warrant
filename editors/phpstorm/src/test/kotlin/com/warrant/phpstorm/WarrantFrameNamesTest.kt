package com.warrant.phpstorm

import com.intellij.ide.DataManager
import com.intellij.openapi.fileEditor.FileDocumentManager
import com.intellij.openapi.vfs.VirtualFile
import com.intellij.psi.PsiDocumentManager
import com.intellij.psi.util.PsiTreeUtil
import com.intellij.testFramework.fixtures.BasePlatformTestCase
import com.intellij.testFramework.replaceService
import com.jetbrains.php.lang.psi.elements.PhpClass
import com.warrant.phpstorm.lsp.WarrantFrameRenameHandler
import com.warrant.phpstorm.lsp.WarrantFrameUsageTargetProvider
import com.warrant.phpstorm.lsp.WarrantKind
import com.warrant.phpstorm.lsp.WarrantServerQueries

/**
 * Frame names, which no PHP member declares, are renamed and found from where
 * rule text writes them; a schema's uses in rule text are found from its class,
 * and renaming the class leaves them.
 */
class WarrantFrameNamesTest : BasePlatformTestCase() {

    private lateinit var server: FakeServer

    override fun setUp() {
        super.setUp()
        myFixture.addFileToProject("vendor/warrant/Attributes.php", WARRANT_ATTRIBUTES.trim())
        server = FakeServer(project)
        project.replaceService(WarrantServerQueries::class.java, server, testRootDisposable)
    }

    private val schema = """
        <?php
        namespace App;

        use Warrant\Schema\Types\Interfaces\ISchema;

        class DocumentSchema implements ISchema
        {
        }
    """.trimIndent()

    private val rules = "if is_owner they can edit\nif check(in_folder(@column d.id) for documents(1) as d) they can view"

    private fun configure(): Pair<VirtualFile, VirtualFile> {
        val schemaFile = myFixture.addFileToProject("app/DocumentSchema.php", schema).virtualFile
        val rulesFile = myFixture.addFileToProject("resources/documents.warrant", rules).virtualFile

        server.occurs("frame", "d", listOf(rulesFile to rules.indexOf("d.id"), rulesFile to rules.indexOf("as d") + 3))

        return schemaFile to rulesFile
    }

    private fun text(file: VirtualFile): String {
        PsiDocumentManager.getInstance(project).commitAllDocuments()

        return FileDocumentManager.getInstance().getDocument(file)!!.text
    }

    private fun renameAt(file: VirtualFile, offset: Int, newName: String) {
        myFixture.configureFromExistingVirtualFile(file)
        myFixture.editor.caretModel.moveToOffset(offset)
        myFixture.renameElementAtCaretUsingHandler(newName)
    }

    fun testRenamingAFrameRenamesItsAsAndEachColumnReferringToIt() {
        val (_, rulesFile) = configure()

        renameAt(rulesFile, rules.indexOf("as d") + 3, "doc")

        assertTrue(text(rulesFile).endsWith("if check(in_folder(@column doc.id) for documents(1) as doc) they can view"))
    }

    fun testAContextKeyIsNeitherRenamedNorSearchedFor() {
        val rulesFile = myFixture.addFileToProject("resources/context.warrant", "if in_folder(@context org) they can edit").virtualFile
        server.occurs("context", "org", listOf(rulesFile to 22))
        myFixture.configureFromExistingVirtualFile(rulesFile)
        myFixture.editor.caretModel.moveToOffset(23)

        val context = DataManager.getInstance().getDataContext(myFixture.editor.component)

        assertFalse(WarrantFrameRenameHandler().isAvailableOnDataContext(context))
        assertNull(WarrantFrameUsageTargetProvider().getTargets(myFixture.editor, myFixture.file))
    }

    fun testFindsTheUsesOfASchemaFromItsClassAndRenamingTheClassLeavesThem() {
        val (schemaFile, rulesFile) = configure()
        server.searched = listOf("resources/documents.warrant")
        server.declare(schemaFile, "App\\DocumentSchema", WarrantKind.SCHEMA, "documents")
        val phpClass = PsiTreeUtil.findChildrenOfType(psiManager.findFile(schemaFile), PhpClass::class.java).single()

        assertEquals(1, myFixture.findUsages(phpClass).count { it.virtualFile == rulesFile })

        myFixture.renameElement(phpClass, "DocSchema")

        assertEquals(rules, text(rulesFile))
    }
}
