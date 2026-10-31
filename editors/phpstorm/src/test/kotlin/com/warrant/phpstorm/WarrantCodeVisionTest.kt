package com.warrant.phpstorm

import com.intellij.psi.util.PsiTreeUtil
import com.intellij.testFramework.fixtures.BasePlatformTestCase
import com.intellij.testFramework.replaceService
import com.jetbrains.php.hints.PhpReferencesCodeVisionProvider
import com.jetbrains.php.lang.psi.elements.Method
import com.warrant.phpstorm.lsp.WarrantKind
import com.warrant.phpstorm.lsp.WarrantServerQueries

/**
 * The usage count PhpStorm shows over a condition method counts the rule text
 * naming it, though no file but the method's own writes the method's name.
 */
class WarrantCodeVisionTest : BasePlatformTestCase() {

    fun testCountsUsesInAWarrantFileWhereOnlyTheSnakeCaseNameIsWritten() {
        myFixture.addFileToProject("vendor/warrant/Attributes.php", WARRANT_ATTRIBUTES.trim())
        myFixture.addFileToProject("resources/documents.warrant", "if not is_awaiting_publish they can edit")
        val schema = myFixture.addFileToProject(
            "app/DocumentSchema.php",
            """
            <?php
            namespace App;

            use Warrant\Schema\Conditions\Row\RowCondition;

            class DocumentSchema
            {
                #[RowCondition]
                public function isAwaitingPublish() {}
            }
            """.trimIndent(),
        )

        val server = FakeServer(project)
        server.declare(schema.virtualFile, "isAwaitingPublish", WarrantKind.CONDITION, "is_awaiting_publish")
        project.replaceService(WarrantServerQueries::class.java, server, testRootDisposable)

        val method = PsiTreeUtil.findChildrenOfType(schema, Method::class.java).single()

        assertEquals("1 usage", PhpReferencesCodeVisionProvider().getVisionInfo(method, schema)?.text)
    }


    fun testCountsUsesOfAMethodAnotherClassDeclaresByTheSameName() {
        myFixture.addFileToProject("vendor/warrant/Attributes.php", WARRANT_ATTRIBUTES.trim())
        myFixture.addFileToProject("resources/documents.warrant", "if is_approved they can edit")
        myFixture.addFileToProject(
            "app/TimesheetSchema.php",
            """
            <?php
            namespace App;

            use Warrant\Schema\Conditions\Row\RowCondition;

            class TimesheetSchema
            {
                #[RowCondition]
                public function isApproved() {}
            }
            """.trimIndent(),
        )
        val schema = myFixture.addFileToProject(
            "app/DocumentSchema.php",
            """
            <?php
            namespace App;

            use Warrant\Schema\Conditions\Row\RowCondition;

            class DocumentSchema
            {
                #[RowCondition]
                public function isApproved() {}
            }
            """.trimIndent(),
        )

        val server = FakeServer(project)
        server.declare(schema.virtualFile, "isApproved", WarrantKind.CONDITION, "is_approved")
        project.replaceService(WarrantServerQueries::class.java, server, testRootDisposable)

        val method = PsiTreeUtil.findChildrenOfType(schema, Method::class.java).single()

        assertEquals("1 usage", PhpReferencesCodeVisionProvider().getVisionInfo(method, schema)?.text)
    }

    fun testCountsUsesInRuleTextInAnotherPhpFile() {
        myFixture.addFileToProject("vendor/warrant/Attributes.php", WARRANT_ATTRIBUTES.trim())
        myFixture.addFileToProject("resources/documents.warrant", "")
        myFixture.addFileToProject(
            "tests/ModuleEnabledTest.php",
            """
            <?php
            class ModuleEnabledTestSchema
            {
                public function rules(): string
                {
                    return <<<'WARRANT'
                        if module_enabled('payroll') they can view
                    WARRANT;
                }
            }
            """.trimIndent(),
        )
        val schema = myFixture.addFileToProject(
            "app/DocumentSchema.php",
            """
            <?php
            namespace App;

            use Warrant\Schema\Conditions\Row\RowCondition;

            class DocumentSchema
            {
                #[RowCondition]
                public function moduleEnabled() {}

                public function rules(): string
                {
                    return <<<'WARRANT'
                        if not module_enabled('x') they cannot
                    WARRANT;
                }
            }
            """.trimIndent(),
        )

        val server = FakeServer(project)
        server.searched = listOf("app/DocumentSchema.php", "tests/ModuleEnabledTest.php")
        server.declare(schema.virtualFile, "moduleEnabled", WarrantKind.CONDITION, "module_enabled")
        project.replaceService(WarrantServerQueries::class.java, server, testRootDisposable)

        val method = PsiTreeUtil.findChildrenOfType(schema, Method::class.java).single { it.name == "moduleEnabled" }

        assertEquals("2 usages", PhpReferencesCodeVisionProvider().getVisionInfo(method, schema)?.text)
    }
}
