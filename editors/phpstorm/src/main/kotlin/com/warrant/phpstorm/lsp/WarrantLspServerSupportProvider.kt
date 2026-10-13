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
 * [WarrantServerLaunch.resolve]). One server serves the whole project.
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
    }

    override fun createLspServerWidgetItem(lspServer: LspServer, currentFile: VirtualFile?): LspServerWidgetItem =
        LspServerWidgetItem(lspServer, currentFile, AllIcons.FileTypes.Text, WarrantSettingsConfigurable::class.java)

    companion object {
        /**
         * Stops the project's Warrant server and starts it again if the
         * current settings and open files call for one.
         */
        fun restart(project: Project) {
            LspServerManager.getInstance(project)
                .stopAndRestartIfNeeded(WarrantLspServerSupportProvider::class.java)
        }
    }
}
