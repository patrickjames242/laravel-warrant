package com.warrant.phpstorm.lsp

import com.intellij.notification.NotificationGroupManager
import com.intellij.notification.NotificationType
import com.intellij.openapi.actionSystem.ActionUpdateThread
import com.intellij.openapi.actionSystem.AnAction
import com.intellij.openapi.actionSystem.AnActionEvent

/**
 * Tools → Restart Warrant Language Server. Stops the project's server and
 * starts it again, so it re-reads the project from scratch. When no server can
 * start, a notification says why.
 */
class RestartWarrantLanguageServerAction : AnAction() {

    override fun getActionUpdateThread(): ActionUpdateThread = ActionUpdateThread.BGT

    override fun update(e: AnActionEvent) {
        e.presentation.isEnabledAndVisible = e.project != null
    }

    override fun actionPerformed(e: AnActionEvent) {
        val project = e.project ?: return

        WarrantLspServerSupportProvider.restart(project)

        if (WarrantServerLaunch.resolve(project) != null) return

        val reason = if (!WarrantSettings.getInstance(project).state.enabled) {
            "The Warrant language server is disabled in Settings → Languages &amp; Frameworks → Warrant."
        } else {
            "No warrant-language-server script was found. Looked for the configured server path, then " +
                WarrantServerLaunch.SCRIPT_CANDIDATES.joinToString(", ") + "."
        }

        NotificationGroupManager.getInstance()
            .getNotificationGroup(NOTIFICATION_GROUP)
            .createNotification("Warrant language server not started", reason, NotificationType.WARNING)
            .notify(project)
    }

    companion object {
        const val NOTIFICATION_GROUP = "Warrant"
    }
}
