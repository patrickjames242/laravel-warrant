package com.warrant.phpstorm.lsp

import com.intellij.openapi.application.ApplicationManager
import com.intellij.openapi.application.ModalityState
import com.intellij.openapi.components.Service
import com.intellij.openapi.components.service
import com.intellij.openapi.fileEditor.FileEditorManager
import com.intellij.openapi.project.Project
import com.intellij.platform.lsp.api.LspServer
import com.intellij.platform.lsp.api.LspServerManager
import com.intellij.platform.lsp.api.LspServerState
import com.intellij.util.FileContentUtil
import java.util.concurrent.atomic.AtomicBoolean

/**
 * Has the open PHP and Warrant files counted again once the Warrant language
 * server is running.
 *
 * What counts usages, the usage counts over methods among it, keeps its counts
 * until the code changes, so a count taken before the server was running, which
 * finds nothing in rule text, would stay. Reparsing the open files is a change
 * that has every count taken again.
 */
@Service(Service.Level.PROJECT)
class WarrantRecountOnServerStart(private val project: Project) {

    private val waiting = AtomicBoolean(false)

    /**
     * Waits in the background, for up to a minute, for a server other than
     * [replaced] to be running, then has the open files counted again. Nothing
     * is done when one is running already, or a wait is under way.
     *
     * @param replaced The server a restart is replacing, which does not count
     *   as running, though it may for a moment after the restart begins.
     */
    fun recountOnceRunning(replaced: LspServer? = null) {
        if (runningServer(replaced) != null || !waiting.compareAndSet(false, true)) return

        ApplicationManager.getApplication().executeOnPooledThread {
            try {
                repeat(WAIT_STEPS) {
                    if (project.isDisposed) return@executeOnPooledThread

                    if (runningServer(replaced) != null) {
                        // A reparse changes the model, which is allowed only outside any modal dialog.
                        ApplicationManager.getApplication().invokeLater({ recount() }, ModalityState.nonModal(), project.disposed)
                        return@executeOnPooledThread
                    }

                    Thread.sleep(WAIT_STEP_MS)
                }
            } finally {
                waiting.set(false)
            }
        }
    }

    /** The project's running Warrant server, other than [other]. */
    fun runningServer(other: LspServer? = null): LspServer? =
        LspServerManager.getInstance(project)
            .getServersForProvider(WarrantLspServerSupportProvider::class.java)
            .firstOrNull { it.state == LspServerState.Running && it !== other }

    private fun recount() {
        val files = FileEditorManager.getInstance(project).openFiles
            .filter { WarrantLspServerDescriptor.isWarrantOrPhpFile(it) }

        if (files.isNotEmpty()) FileContentUtil.reparseFiles(project, files, false)
    }

    companion object {
        private const val WAIT_STEP_MS = 200L

        /** A minute, in steps of [WAIT_STEP_MS]. */
        private const val WAIT_STEPS = 300

        fun getInstance(project: Project): WarrantRecountOnServerStart = project.service()
    }
}
