package com.warrant.phpstorm.lsp

import com.intellij.openapi.project.Project
import com.intellij.refactoring.listeners.RefactoringEventData
import com.intellij.refactoring.listeners.RefactoringEventListener

/**
 * Warns when a condition, template or row relation is renamed while the search
 * for its uses in rule text timed out, since the rename then leaves those uses
 * with the old name. A rename finds its uses before it starts, so the search
 * has run by the time [refactoringStarted] is told of it.
 */
class WarrantRenameAfterUnansweredSearch(private val project: Project) : RefactoringEventListener {

    override fun refactoringStarted(refactoringId: String, beforeData: RefactoringEventData?) {
        if (refactoringId != RENAME) return

        val element = beforeData?.getUserData(RefactoringEventData.PSI_ELEMENT_KEY) ?: return
        val construct = WarrantConstructs.declaredBy(element)?.takeIf { it.isRenamable } ?: return
        val queries = WarrantServerQueries.getInstance(project)

        if (queries.searchTimedOut(construct.file, construct.member)) {
            queries.notifyRenamedUnsearched(construct.member)
        }
    }

    override fun refactoringDone(refactoringId: String, afterData: RefactoringEventData?) {}

    private companion object {
        const val RENAME = "refactoring.rename"
    }
}
