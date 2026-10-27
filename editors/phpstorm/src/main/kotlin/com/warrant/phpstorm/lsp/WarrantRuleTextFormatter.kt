package com.warrant.phpstorm.lsp

import com.intellij.execution.configurations.GeneralCommandLine
import com.intellij.execution.process.CapturingProcessHandler
import com.intellij.openapi.diagnostic.logger
import com.intellij.openapi.editor.Document
import com.intellij.openapi.project.Project
import java.nio.charset.StandardCharsets
import java.nio.file.Files

/**
 * Formats the Warrant in a file's text by running the project's
 * `warrant-format` with that text on standard input, the way an editor runs an
 * external formatter: `<php> warrant-format --stdin-filepath=<path>`.
 *
 * The text is handed over as the editor has it, so what is formatted is
 * always what is on screen. The script is the one beside the language server's,
 * from [WarrantServerLaunch], so it is the same Warrant as the server's.
 */
object WarrantRuleTextFormatter {
    private val LOG = logger<WarrantRuleTextFormatter>()

    private const val TIMEOUT_MS = 10_000

    /**
     * Rule text can stand in a PHP file only where one of these is written, so a
     * file with none is not handed to PHP at all.
     */
    private val RULE_TEXT_MARKERS = Regex("""WARRANT|warrant\s*\(|::parse\s*\(|Raw\s*\(|DerivedCondition|RuleTemplate|function\s+rules\s*\(""", RegexOption.IGNORE_CASE)

    /** Whether a PHP file's [text] could hold rule text. */
    fun mightHoldRuleText(text: CharSequence): Boolean = RULE_TEXT_MARKERS.containsMatchIn(text)

    /**
     * [text] with the Warrant in it formatted, as the file at [path] would be,
     * or null when it cannot be: no `warrant-format` script, or the script
     * failed. Rule text that does not parse is left as it is in the answer.
     */
    fun formatted(project: Project, path: String, text: String): String? {
        val launch = WarrantServerLaunch.resolve(project) ?: return null
        val script = launch.script.resolveSibling("warrant-format")

        if (!Files.isRegularFile(script)) {
            LOG.info("No warrant-format script beside ${launch.script}, so the Warrant in $path is not formatted")
            return null
        }

        val commandLine = GeneralCommandLine(launch.phpExecutable, script.toString(), "--stdin-filepath=$path")
            .withWorkDirectory(launch.workDirectory.toFile())
            .withParentEnvironmentType(GeneralCommandLine.ParentEnvironmentType.CONSOLE)
            .withCharset(StandardCharsets.UTF_8)

        return try {
            val process = commandLine.createProcess()
            // The script reads all of its input before it writes any output.
            process.outputStream.use { it.write(text.toByteArray(StandardCharsets.UTF_8)) }
            val output = CapturingProcessHandler(process, StandardCharsets.UTF_8, commandLine.commandLineString).runProcess(TIMEOUT_MS)

            when {
                output.isTimeout -> null.also { LOG.warn("warrant-format took too long on $path") }
                // 1 means some rule text did not parse; the rest is formatted.
                output.exitCode != 0 && output.exitCode != 1 -> null.also { LOG.warn("warrant-format failed on $path: ${output.stderr}") }
                else -> output.stdout
            }
        } catch (e: Exception) {
            LOG.warn("Could not run warrant-format on $path", e)
            null
        }
    }

    /**
     * Replace the part of [document] that differs from [formatted], so the rest
     * of the document, and the caret in it, stay where they are.
     */
    fun applyTo(document: Document, formatted: String) {
        val text = document.text
        val start = text.commonPrefixWith(formatted).length
        val suffix = text.substring(start).commonSuffixWith(formatted.substring(start)).length

        if (start == text.length && start == formatted.length) return

        document.replaceString(start, text.length - suffix, formatted.substring(start, formatted.length - suffix))
    }
}
