package com.warrant.phpstorm

import com.intellij.testFramework.fixtures.BasePlatformTestCase
import com.intellij.testFramework.replaceService
import com.jetbrains.php.lang.inspections.deadcode.PhpUnusedDeclarationInspection
import com.warrant.phpstorm.lsp.WarrantKind
import com.warrant.phpstorm.lsp.WarrantServerQueries

/**
 * A condition or template method, or an ability constant, shows as unused only
 * when the language server says no rule text names it.
 */
class WarrantEntryPointTest : BasePlatformTestCase() {

    fun testAWarrantMemberIsUnusedOnlyWhenNoRuleTextNamesIt() {
        myFixture.addFileToProject("vendor/warrant/Attributes.php", WARRANT_ATTRIBUTES.trim())
        val rules = myFixture.addFileToProject("resources/documents.warrant", "if is_owner they can edit")
        val schema = myFixture.addFileToProject(
            "app/DocumentSchema.php",
            """
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
                public const ARCHIVE = 'archive';

                #[Ability]
                public const VIEW = Other::VIEW;

                public const PLAIN = 'plain';

                #[RowCondition]
                public function isOwner() {}

                #[RowCondition]
                public function isLocked() {}

                #[RuleTemplate]
                public function ownerEdits() {}

                #[RowCondition]
                public function unknownToTheServer() {}

                public function plainMethod() {}
            }
            """.trimIndent(),
        ).virtualFile

        val server = FakeServer(project)
        server.declare(schema, "isOwner", WarrantKind.CONDITION, "is_owner")
        server.declare(schema, "isLocked", WarrantKind.CONDITION, "is_locked")
        server.declare(schema, "ownerEdits", WarrantKind.TEMPLATE, "owner_edits")
        server.declare(schema, "EDIT", WarrantKind.ABILITY, "edit")
        server.declare(schema, "ARCHIVE", WarrantKind.ABILITY, "archive")
        project.replaceService(WarrantServerQueries::class.java, server, testRootDisposable)

        myFixture.enableInspections(PhpUnusedDeclarationInspection())
        myFixture.configureFromExistingVirtualFile(schema)

        val unused = myFixture.doHighlighting()
            .filter { it.description?.contains("unused", ignoreCase = true) == true }
            .map { it.text }
            // Nothing in the test project names the schema class, as config/warrant.php would.
            .filter { it != "DocumentSchema" }

        assertNotNull(rules)
        assertSameElements(unused, "plainMethod", "PLAIN", "isLocked", "ownerEdits", "ARCHIVE")
    }
}
