package com.warrant.phpstorm.lsp

import com.intellij.notification.NotificationGroupManager
import com.intellij.notification.NotificationType
import com.intellij.openapi.actionSystem.ActionUpdateThread
import com.intellij.openapi.actionSystem.AnAction
import com.intellij.openapi.actionSystem.AnActionEvent
import com.intellij.openapi.actionSystem.CommonDataKeys
import com.intellij.openapi.command.WriteCommandAction
import com.intellij.openapi.fileEditor.FileDocumentManager
import com.intellij.openapi.progress.ProgressManager
import com.intellij.openapi.project.Project

/**
 * Code → Reformat Warrant Rules. Formats only the Warrant in the current file:
 * every piece of rule text in a PHP file, leaving the PHP around it as it is,
 * or all of a `.warrant` file. Reformat Code formats the Warrant too, through
 * [WarrantPostFormatProcessor], along with the PHP.
 */
class ReformatWarrantRulesAction : AnAction() {

    override fun getActionUpdateThread(): ActionUpdateThread = ActionUpdateThread.BGT

    override fun update(e: AnActionEvent) {
        val file = e.getData(CommonDataKeys.VIRTUAL_FILE)
        e.presentation.isEnabledAndVisible = e.project != null && file != null &&
            WarrantLspServerDescriptor.isWarrantOrPhpFile(file)
    }

    override fun actionPerformed(e: AnActionEvent) {
        val project = e.project ?: return
        val file = e.getData(CommonDataKeys.VIRTUAL_FILE) ?: return
        val document = FileDocumentManager.getInstance().getDocument(file) ?: return
        val text = document.text

        val formatted = ProgressManager.getInstance().runProcessWithProgressSynchronously<String?, RuntimeException>(
            { WarrantRuleTextFormatter.formatted(project, file.path, text) },
            "Formatting Warrant rules",
            true,
            project,
        )

        if (formatted == null) {
            notify(project, "No warrant-format script was found beside the language server's, or it failed. See the IDE log.")
            return
        }

        if (document.text != text) return

        WriteCommandAction.runWriteCommandAction(project, "Reformat Warrant Rules", null, {
            WarrantRuleTextFormatter.applyTo(document, formatted)
        })
    }

    private fun notify(project: Project, message: String) {
        NotificationGroupManager.getInstance()
            .getNotificationGroup(RestartWarrantLanguageServerAction.NOTIFICATION_GROUP)
            .createNotification("Warrant rules not formatted", message, NotificationType.WARNING)
            .notify(project)
    }
}
