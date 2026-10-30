package com.warrant.phpstorm

import com.intellij.openapi.fileEditor.FileDocumentManager
import com.intellij.openapi.vfs.VirtualFile
import com.intellij.psi.PsiDocumentManager
import com.intellij.psi.PsiElement
import com.intellij.psi.util.PsiTreeUtil
import com.intellij.refactoring.rename.RenameInputValidatorRegistry
import com.intellij.testFramework.replaceService
import com.intellij.testFramework.fixtures.BasePlatformTestCase
import com.jetbrains.php.lang.psi.elements.Field
import com.jetbrains.php.lang.psi.elements.Method
import com.jetbrains.php.lang.psi.elements.StringLiteralExpression
import com.warrant.phpstorm.lsp.WarrantConstructs
import com.warrant.phpstorm.lsp.WarrantDeclaration
import com.warrant.phpstorm.lsp.WarrantKind
import com.warrant.phpstorm.lsp.WarrantNameStringGotoUsagesHandler
import com.warrant.phpstorm.lsp.WarrantNameStringsAfterRename
import com.warrant.phpstorm.lsp.WarrantServerQueries

/**
 * Renaming a condition, template or ability renames its uses in rule text, and
 * Find Usages finds them, wherever the rename or search starts. The language
 * server is stood in for by [FakeServer], which finds a name's uses by its
 * spelling, as the server would in a project with one schema.
 */
class WarrantRenameTest : BasePlatformTestCase() {

    private lateinit var server: FakeServer

    override fun setUp() {
        super.setUp()

        myFixture.addFileToProject(
            "vendor/warrant/Attributes.php",
            WARRANT_ATTRIBUTES.trim(),
        )

        server = FakeServer(project)
        project.replaceService(WarrantServerQueries::class.java, server, testRootDisposable)
    }

    private val schema = """
        <?php
        namespace App;

        use Warrant\Schema\Abilities\Ability;
        use Warrant\Schema\Conditions\Row\RowCondition;
        use Warrant\Schema\RuleTemplates\RuleTemplate;

        class DocumentSchema
        {
            #[Ability]
            public const EDIT = 'edit';

            #[Ability]
            public const VIEW = Other::VIEW;

            #[RowCondition]
            public function isOwner(${'$'}c) {}

            #[RowCondition(key: 'in_folder')]
            public function insideFolder(${'$'}c, ${'$'}folder) {}

            #[RuleTemplate]
            public function ownerEdits() {}

            public function rules(): string
            {
                return <<<'WARRANT'
                    if is_owner they can edit
                    if in_folder(1) they can edit
                    WARRANT;
            }

            public function derived()
            {
                return Warrant::parse(new BoundSyntax('is_owner or in_folder(2)'));
            }
        }
    """.trimIndent()

    private fun configure(rules: String = "if is_owner they can edit\n@include owner_edits"): Pair<VirtualFile, VirtualFile> {
        val schemaFile = myFixture.addFileToProject("app/DocumentSchema.php", schema).virtualFile
        val rulesFile = myFixture.addFileToProject("resources/documents.warrant", rules).virtualFile

        server.declare(schemaFile, "isOwner", WarrantKind.CONDITION, "is_owner", taken = listOf("in_folder"))
        server.declare(schemaFile, "insideFolder", WarrantKind.CONDITION, "in_folder", taken = listOf("is_owner"))
        server.declare(schemaFile, "ownerEdits", WarrantKind.TEMPLATE, "owner_edits")
        server.declare(schemaFile, "EDIT", WarrantKind.ABILITY, "edit", taken = listOf("view"))

        return schemaFile to rulesFile
    }

    private inline fun <reified T : PsiElement> element(file: VirtualFile, crossinline accept: (T) -> Boolean): T =
        PsiTreeUtil.findChildrenOfType(psiManager.findFile(file), T::class.java).first { accept(it) }

    private fun text(file: VirtualFile): String {
        PsiDocumentManager.getInstance(project).commitAllDocuments()

        return FileDocumentManager.getInstance().getDocument(file)!!.text
    }

    fun testRenamingAConditionMethodRenamesItsUsesInRuleTextInSnakeCase() {
        val (schemaFile, rulesFile) = configure()

        myFixture.renameElement(element<Method>(schemaFile) { it.name == "isOwner" }, "isAuthor")

        assertEquals("if is_author they can edit\n@include owner_edits", text(rulesFile))
        assertTrue(text(schemaFile).contains("if is_author they can edit\n"))
        assertTrue(text(schemaFile).contains("new BoundSyntax('is_author or in_folder(2)')"))
        assertTrue(text(schemaFile).contains("public function isAuthor("))
    }

    fun testRenamingATemplateMethodRenamesItsIncludes() {
        val (schemaFile, rulesFile) = configure()

        myFixture.renameElement(element<Method>(schemaFile) { it.name == "ownerEdits" }, "authorEdits")

        assertEquals("if is_owner they can edit\n@include author_edits", text(rulesFile))
    }

    fun testRenamingAKeyRenamesItsUsesAndLeavesTheMethod() {
        val (schemaFile, _) = configure()

        myFixture.renameElement(element<StringLiteralExpression>(schemaFile) { it.contents == "in_folder" }, "in_directory")

        val text = text(schemaFile)
        assertTrue(text.contains("#[RowCondition(key: 'in_directory')]"))
        assertTrue(text.contains("public function insideFolder("))
        assertTrue(text.contains("if in_directory(1) they can edit"))
        assertTrue(text.contains("'is_owner or in_directory(2)'"))
    }

    fun testRenamingAKeyedMethodLeavesRuleTextAlone() {
        val (schemaFile, _) = configure()

        myFixture.renameElement(element<Method>(schemaFile) { it.name == "insideFolder" }, "withinFolder")

        assertTrue(text(schemaFile).contains("if in_folder(1) they can edit"))
    }

    fun testRenamingAnAbilityRenamesItsValueAndUsesButNotTheConstant() {
        val (schemaFile, rulesFile) = configure()

        myFixture.renameElement(element<StringLiteralExpression>(schemaFile) { it.contents == "edit" }, "modify")

        assertEquals("if is_owner they can modify\n@include owner_edits", text(rulesFile))
        assertTrue(text(schemaFile).contains("public const EDIT = 'modify';"))
        assertTrue(text(schemaFile).contains("if in_folder(1) they can modify"))
    }

    fun testRenamingANameInRuleTextRenamesWhatDeclaresIt() {
        val (schemaFile, rulesFile) = configure()
        myFixture.configureFromExistingVirtualFile(rulesFile)
        myFixture.editor.caretModel.moveToOffset(text(rulesFile).indexOf("is_owner") + 2)

        myFixture.renameElementAtCaret("isAuthor")

        assertEquals("if is_author they can edit\n@include owner_edits", text(rulesFile))
        assertTrue(text(schemaFile).contains("public function isAuthor("))
    }

    fun testRenamingAnAbilityNameInRuleTextRenamesTheConstantsValue() {
        val (schemaFile, rulesFile) = configure()
        myFixture.configureFromExistingVirtualFile(rulesFile)
        myFixture.editor.caretModel.moveToOffset(text(rulesFile).indexOf("edit") + 1)

        myFixture.renameElementAtCaret("modify")

        assertEquals("if is_owner they can modify\n@include owner_edits", text(rulesFile))
        assertTrue(text(schemaFile).contains("public const EDIT = 'modify';"))
    }

    fun testRenamingFromTheStringAtTheCaret() {
        val (schemaFile, rulesFile) = configure()
        myFixture.configureFromExistingVirtualFile(schemaFile)
        myFixture.editor.caretModel.moveToOffset(text(schemaFile).indexOf("'edit'") + 2)

        myFixture.renameElementAtCaretUsingHandler("modify")

        assertEquals("if is_owner they can modify\n@include owner_edits", text(rulesFile))
    }

    fun testFindsTheUsesOfAMethodAndOfAString() {
        val (schemaFile, rulesFile) = configure()

        val ofMethod = myFixture.findUsages(element<Method>(schemaFile) { it.name == "isOwner" })
        assertEquals(3, ofMethod.size)
        assertTrue(ofMethod.any { it.virtualFile == rulesFile })

        val ofAbility = myFixture.findUsages(element<StringLiteralExpression>(schemaFile) { it.contents == "edit" })
        assertEquals(3, ofAbility.size)
    }

    fun testCmdClickOnAnAbilitysValueOffersItsUses() {
        val (schemaFile, _) = configure()
        myFixture.configureFromExistingVirtualFile(schemaFile)
        val offset = text(schemaFile).indexOf("'edit'") + 2

        val targets = WarrantNameStringGotoUsagesHandler().getGotoDeclarationTargets(myFixture.file.findElementAt(offset), offset, myFixture.editor)

        assertEquals(3, targets!!.size)
    }

    fun testANameInRuleTextResolvesToWhatDeclaresItsName() {
        val (schemaFile, rulesFile) = configure()

        server.at(rulesFile, text(rulesFile).indexOf("is_owner"), schemaFile, "isOwner", WarrantKind.CONDITION)
        server.at(rulesFile, text(rulesFile).indexOf("edit"), schemaFile, "EDIT", WarrantKind.ABILITY)

        myFixture.configureFromExistingVirtualFile(rulesFile)
        val rules = myFixture.file

        assertEquals("isOwner", (rules.findReferenceAt(text(rulesFile).indexOf("is_owner"))!!.resolve() as Method).name)
        assertEquals("edit", (rules.findReferenceAt(text(rulesFile).indexOf("edit"))!!.resolve() as StringLiteralExpression).contents)
    }

    fun testAComputedAbilityResolvesToItsConstant() {
        val (schemaFile, _) = configure()

        val declared = WarrantConstructs.declaringElement(project, WarrantDeclaration("ability", "view", schemaFile.url, "VIEW"))

        assertEquals("VIEW", (declared as Field).name)
        assertNull(WarrantConstructs.declaredBy(declared))
    }

    fun testANewNameMustBeWritableAndFree() {
        val (schemaFile, _) = configure()
        val method = element<Method>(schemaFile) { it.name == "isOwner" }
        val ability = element<StringLiteralExpression>(schemaFile) { it.contents == "edit" }

        assertTrue(validatorFor(method, "isAuthor"))
        assertFalse(validatorFor(method, "inFolder"))
        assertFalse(validatorFor(method, "or"))
        assertFalse(validatorFor(method, "is-owner"))
        assertFalse(validatorFor(ability, "view"))
        assertFalse(validatorFor(ability, "can"))
        assertFalse(validatorFor(ability, "two words"))
        assertTrue(validatorFor(ability, "modify"))
        assertTrue(validatorFor(ability, "posts-modify"))
    }

    private fun validatorFor(element: PsiElement, name: String): Boolean =
        RenameInputValidatorRegistry.getInputValidator(element)!!.value(name)

    fun testOffersOnlyStringsPassedWhereANameOfItsKindGoes() {
        myFixture.addFileToProject(
            "app/Uses.php",
            """
            <?php
            use Warrant\Builders\Build;
            use Illuminate\Support\Facades\Gate;

            ${'$'}builder->if('is_owner')->andIfCan('edit');
            Build::check('is_owner');
            Build::can('edit');
            ${'$'}user->can('edit', ${'$'}post);
            Gate::allows('edit', ${'$'}post);
            ${'$'}builder->ifCan('is_owner');
            log('edit');
            ${'$'}user->can(${'$'}x, 'edit');
            """.trimIndent(),
        )

        val conditions = WarrantNameStringsAfterRename.stringsNaming(project, WarrantKind.CONDITION, "is_owner")
        val abilities = WarrantNameStringsAfterRename.stringsNaming(project, WarrantKind.ABILITY, "edit")

        assertEquals(listOf("if", "check"), conditions.map { (it.parent.parent as com.jetbrains.php.lang.psi.elements.FunctionReference).name })
        assertEquals(listOf("andIfCan", "can", "can", "allows"), abilities.map { (it.parent.parent as com.jetbrains.php.lang.psi.elements.FunctionReference).name })
    }

    fun testSnakeCaseFollowsLaravel() {
        assertEquals("is_owner", WarrantConstructs.snakeCase("isOwner"))
        assertEquals("owner_or_team", WarrantConstructs.snakeCase("ownerOrTeam"))
        assertEquals("is_o_k", WarrantConstructs.snakeCase("isOK"))
        assertEquals("already_snake", WarrantConstructs.snakeCase("already_snake"))
        assertEquals("is2_fa", WarrantConstructs.snakeCase("is2Fa"))
    }
}
