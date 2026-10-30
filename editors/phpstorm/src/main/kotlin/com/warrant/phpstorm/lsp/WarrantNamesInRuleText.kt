package com.warrant.phpstorm.lsp

import com.intellij.codeInsight.daemon.DaemonCodeAnalyzer
import com.intellij.openapi.application.ApplicationManager
import com.intellij.openapi.components.Service
import com.intellij.openapi.components.service
import com.intellij.openapi.project.Project
import com.intellij.psi.util.PsiModificationTracker
import java.util.concurrent.ConcurrentHashMap

/**
 * Whether rule text names each condition, template and ability, as the language
 * server last said, for showing which are unused without waiting on the server.
 *
 * An answer is asked for in the background when none is known or the code has
 * changed since, one request at a time for each declaration, and the editor's
 * highlighting is redone when an answer changes. Until the server has answered,
 * a declaration counts as named.
 */
@Service(Service.Level.PROJECT)
class WarrantNamesInRuleText(private val project: Project) {

    /** Whether it is named, and the PSI modification count the answer was asked at. */
    private data class Answer(val named: Boolean, val asOf: Long)

    private val answers = ConcurrentHashMap<Pair<String, String>, Answer>()

    private val asking = ConcurrentHashMap.newKeySet<Pair<String, String>>()

    /** Whether rule text names [construct], as last known; true when nothing is known yet. */
    fun isNamed(construct: WarrantConstruct): Boolean {
        val key = construct.file.url to construct.member
        val now = PsiModificationTracker.getInstance(project).modificationCount
        val known = answers[key]

        if (known == null || known.asOf != now) {
            if (ApplicationManager.getApplication().isUnitTestMode) {
                ask(key, construct, now)
            } else if (asking.add(key)) {
                ApplicationManager.getApplication().executeOnPooledThread {
                    try {
                        ask(key, construct, now)
                    } finally {
                        asking.remove(key)
                    }
                }
            }
        }

        return answers[key]?.named ?: true
    }

    private fun ask(key: Pair<String, String>, construct: WarrantConstruct, asOf: Long) {
        val usages = WarrantServerQueries.getInstance(project)
            .usagesOf(construct.file, construct.member, notifyIfUnsearched = false)
            ?: return

        val named = !usages.usages.isNullOrEmpty()
        val before = answers.put(key, Answer(named, asOf))

        if (before?.named != named && !ApplicationManager.getApplication().isUnitTestMode) {
            ApplicationManager.getApplication().invokeLater({ DaemonCodeAnalyzer.getInstance(project).restart() }, project.disposed)
        }
    }

    companion object {
        fun getInstance(project: Project): WarrantNamesInRuleText = project.service()
    }
}
