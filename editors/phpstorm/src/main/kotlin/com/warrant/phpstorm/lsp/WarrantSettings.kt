package com.warrant.phpstorm.lsp

import com.intellij.openapi.components.BaseState
import com.intellij.openapi.components.SimplePersistentStateComponent
import com.intellij.openapi.components.State
import com.intellij.openapi.components.Storage
import com.intellij.openapi.project.Project

/**
 * How much of the project the language server reads to learn the schemas,
 * relations and abilities a rule may name. [wireValue] is what the server
 * receives as `initializationOptions.vocabulary`.
 */
enum class WarrantVocabulary(val wireValue: String, private val label: String) {
    /** Reads the PHP files, and boots the app in a short-lived child process. */
    AUTO("auto", "Auto: read PHP files and boot the app"),

    /** Reads the PHP files and never runs project code. */
    STATIC("static", "Static: read PHP files only, never run project code"),

    /** Checks syntax only. */
    OFF("off", "Off: check syntax only");

    override fun toString(): String = label
}

/**
 * The project's Warrant language-server settings, stored in
 * `.idea/warrant.xml`. Changing them through [WarrantSettingsConfigurable]
 * restarts the server.
 */
@State(name = "WarrantSettings", storages = [Storage("warrant.xml")])
class WarrantSettings : SimplePersistentStateComponent<WarrantSettings.Options>(Options()) {

    class Options : BaseState() {
        /** Whether the language server starts at all. */
        var enabled by property(true)

        /** The PHP binary the server runs under. */
        var phpExecutable by string(DEFAULT_PHP_EXECUTABLE)

        /**
         * The server script, absolute or relative to the project base
         * directory. Empty means "look in the usual places".
         */
        var serverPath by string("")

        var vocabulary by enum(WarrantVocabulary.AUTO)
    }

    companion object {
        const val DEFAULT_PHP_EXECUTABLE = "php"

        fun getInstance(project: Project): WarrantSettings =
            project.getService(WarrantSettings::class.java)
    }
}
