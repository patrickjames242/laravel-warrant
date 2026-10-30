package com.warrant.phpstorm.lsp

import com.intellij.icons.AllIcons
import com.intellij.openapi.project.Project
import com.intellij.openapi.vfs.VirtualFile
import com.intellij.platform.lsp.api.LspServer
import com.intellij.platform.lsp.api.LspServerManager
import com.intellij.platform.lsp.api.LspServerSupportProvider
import com.intellij.platform.lsp.api.lsWidget.LspServerWidgetItem

/**
 * Starts the Warrant language server when a `.warrant` or PHP file opens in a
 * project that has the server installed and enabled (see
 * [WarrantServerLaunch.resolve]), and has the open files counted again once it
 * is running ([WarrantRecountOnServerStart]). One server serves the whole
 * project.
 */
class WarrantLspServerSupportProvider : LspServerSupportProvider {

    override fun fileOpened(
        project: Project,
        file: VirtualFile,
        serverStarter: LspServerSupportProvider.LspServerStarter,
    ) {
        if (!WarrantLspServerDescriptor.isWarrantOrPhpFile(file)) return
        if (WarrantServerLaunch.resolve(project) == null) return

        serverStarter.ensureServerStarted(WarrantLspServerDescriptor(project))
        WarrantRecountOnServerStart.getInstance(project).recountOnceRunning()
    }

    override fun createLspServerWidgetItem(lspServer: LspServer, currentFile: VirtualFile?): LspServerWidgetItem =
        LspServerWidgetItem(lspServer, currentFile, AllIcons.FileTypes.Text, WarrantSettingsConfigurable::class.java)

    companion object {
        /**
         * Stops the project's Warrant server and starts it again if the
         * current settings and open files call for one, then has the open
         * files counted again once it is running.
         */
        fun restart(project: Project) {
            val recount = WarrantRecountOnServerStart.getInstance(project)
            val replaced = recount.runningServer()

            LspServerManager.getInstance(project)
                .stopAndRestartIfNeeded(WarrantLspServerSupportProvider::class.java)
            recount.recountOnceRunning(replaced)
        }
    }
}
