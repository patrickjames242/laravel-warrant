package com.warrant.phpstorm.lsp

import com.intellij.execution.configurations.PathEnvironmentVariableUtil
import com.intellij.openapi.diagnostic.logger
import com.intellij.openapi.project.Project
import com.jetbrains.php.config.PhpProjectConfigurationFacade
import java.nio.file.Files
import java.nio.file.InvalidPathException
import java.nio.file.Path

/**
 * The command that starts the Warrant language server for a project:
 * `<php> <script>`, run from the project base directory.
 *
 * The script is always run as an argument to PHP, because
 * `vendor/bin/warrant-language-server` is a Composer proxy script.
 */
data class WarrantServerLaunch(val phpExecutable: String, val script: Path, val workDirectory: Path) {

    companion object {
        private val LOG = logger<WarrantServerLaunch>()

        /**
         * Where the server script is looked for when the "Server path" setting
         * is empty or names no file, in order, relative to the project base
         * directory. The last is the laravel-warrant repository itself.
         */
        val SCRIPT_CANDIDATES = listOf(
            "vendor/bin/warrant-language-server",
            "vendor/patrickhanna/laravel-warrant/bin/warrant-language-server",
            "bin/warrant-language-server",
        )

        /**
         * The launch for [project], or null when the server is disabled or no
         * server script exists, in which case no server is started.
         */
        fun resolve(project: Project): WarrantServerLaunch? {
            val options = WarrantSettings.getInstance(project).state
            if (!options.enabled) return null

            val base = project.basePath?.let(::toPath) ?: return null
            val script = findScript(base, options.serverPath.orEmpty()) ?: return null

            return WarrantServerLaunch(phpExecutable(project, options.phpExecutable.orEmpty()), script, base)
        }

        /**
         * The first server script that exists: the configured path, then each
         * of [SCRIPT_CANDIDATES].
         */
        fun findScript(base: Path, configured: String): Path? {
            val configuredPath = configured.trim().takeIf { it.isNotEmpty() }?.let(::toPath)?.let(base::resolve)

            return (listOfNotNull(configuredPath) + SCRIPT_CANDIDATES.map(base::resolve))
                .firstOrNull(Files::isRegularFile)
        }

        /**
         * The PHP binary to run the server under. A configured value other than
         * the bare default `php` is used as written. Otherwise `php` is looked
         * up on the PATH, then the project's local PHP CLI interpreter is used,
         * and failing both the bare `php` is left for the process launcher.
         */
        fun phpExecutable(project: Project, configured: String): String {
            val trimmed = configured.trim()
            if (trimmed.isNotEmpty() && trimmed != WarrantSettings.DEFAULT_PHP_EXECUTABLE) return trimmed

            PathEnvironmentVariableUtil.findInPath(WarrantSettings.DEFAULT_PHP_EXECUTABLE)?.let { return it.path }

            return projectInterpreter(project) ?: WarrantSettings.DEFAULT_PHP_EXECUTABLE
        }

        /** The executable of the project's PHP CLI interpreter, if it is a local one. */
        private fun projectInterpreter(project: Project): String? =
            try {
                PhpProjectConfigurationFacade.getInstance(project).interpreter
                    ?.takeUnless { it.isRemote }
                    ?.pathToPhpExecutable
                    ?.takeIf { it.isNotBlank() && Files.isRegularFile(Path.of(it)) }
            } catch (e: Exception) {
                LOG.debug("Could not read the project's PHP interpreter", e)
                null
            }

        private fun toPath(text: String): Path? =
            try {
                Path.of(text)
            } catch (e: InvalidPathException) {
                null
            }
    }
}
