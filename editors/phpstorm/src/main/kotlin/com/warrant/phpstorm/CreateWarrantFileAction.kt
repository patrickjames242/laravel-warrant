package com.warrant.phpstorm

import com.intellij.ide.actions.CreateFileFromTemplateAction
import com.intellij.ide.actions.CreateFileFromTemplateDialog
import com.intellij.openapi.project.DumbAware
import com.intellij.openapi.project.Project
import com.intellij.psi.PsiDirectory

/**
 * File → New → Warrant File, also in the project view's New menu. Creates an
 * empty `.warrant` file from the "Warrant File" template.
 */
class CreateWarrantFileAction : CreateFileFromTemplateAction(
    "Warrant File",
    "Create a file of Warrant rules",
    WarrantFileType.INSTANCE.icon,
), DumbAware {

    override fun buildDialog(project: Project, directory: PsiDirectory, builder: CreateFileFromTemplateDialog.Builder) {
        builder.setTitle("New Warrant File")
            .addKind("Warrant file", WarrantFileType.INSTANCE.icon, TEMPLATE)
    }

    override fun getActionName(directory: PsiDirectory?, newName: String, templateName: String?): String =
        "Create Warrant File $newName"

    companion object {
        /** The template, in `fileTemplates/internal/`. */
        const val TEMPLATE = "Warrant File"
    }
}
