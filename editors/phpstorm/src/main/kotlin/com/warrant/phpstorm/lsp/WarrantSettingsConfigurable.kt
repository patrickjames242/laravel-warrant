package com.warrant.phpstorm.lsp

import com.intellij.openapi.options.BoundConfigurable
import com.intellij.openapi.project.Project
import com.intellij.openapi.ui.DialogPanel
import com.intellij.ui.dsl.builder.AlignX
import com.intellij.ui.dsl.builder.bindItem
import com.intellij.ui.dsl.builder.bindSelected
import com.intellij.ui.dsl.builder.bindText
import com.intellij.ui.dsl.builder.panel

/**
 * Settings → Languages & Frameworks → Warrant. Applying a change restarts the
 * project's language server.
 */
class WarrantSettingsConfigurable(private val project: Project) : BoundConfigurable("Warrant") {

    override fun createPanel(): DialogPanel {
        val options = WarrantSettings.getInstance(project).state

        return panel {
            row {
                checkBox("Enable language server")
                    .bindSelected({ options.enabled }, { options.enabled = it })
            }
            row("PHP executable:") {
                textField()
                    .align(AlignX.FILL)
                    .bindText({ options.phpExecutable.orEmpty() }, { options.phpExecutable = it.trim() })
                    .comment(
                        "The PHP binary the server runs under. With <code>php</code>, the one on the PATH " +
                            "is used, or the project's local PHP interpreter if there is none on the PATH.",
                    )
            }
            row("Server path:") {
                textField()
                    .align(AlignX.FILL)
                    .bindText({ options.serverPath.orEmpty() }, { options.serverPath = it.trim() })
                    .comment(
                        "Absolute, or relative to the project. When empty or missing: " +
                            WarrantServerLaunch.SCRIPT_CANDIDATES.joinToString(", ") { "<code>$it</code>" } + ".",
                    )
            }
            row("Vocabulary:") {
                comboBox(WarrantVocabulary.entries)
                    .bindItem({ options.vocabulary }, { options.vocabulary = it ?: WarrantVocabulary.AUTO })
                    .comment("Where the server learns the schemas, relations and abilities a rule may name.")
            }
        }
    }

    override fun apply() {
        super.apply()
        WarrantLspServerSupportProvider.restart(project)
    }
}
