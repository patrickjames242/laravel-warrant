package com.warrant.phpstorm.lsp

import com.intellij.openapi.project.Project
import com.intellij.openapi.startup.ProjectActivity
import com.intellij.platform.lsp.api.LspServerManager

/**
 * Starts the Warrant language server once a project has opened, when a
 * `.warrant` or PHP file is already open in it, and has the open files counted
 * again once it is running ([WarrantRecountOnServerStart]).
 *
 * [WarrantLspServerSupportProvider] starts the server as a file opens, but the
 * tabs a project reopens with load lazily, and a tab that has not loaded yet has
 * not opened as far as the provider is told. Without the server a `.warrant`
 * file has nothing to format it, so it is started here as well.
 */
class StartWarrantLanguageServerActivity : ProjectActivity {
    override suspend fun execute(project: Project) {
        LspServerManager.getInstance(project).startServersIfNeeded(WarrantLspServerSupportProvider::class.java)
        WarrantRecountOnServerStart.getInstance(project).recountOnceRunning()
    }
}
