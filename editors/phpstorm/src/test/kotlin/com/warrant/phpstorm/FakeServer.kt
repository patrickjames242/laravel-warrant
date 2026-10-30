package com.warrant.phpstorm

import com.intellij.openapi.editor.Document
import com.intellij.openapi.fileEditor.FileDocumentManager
import com.intellij.openapi.project.Project
import com.intellij.openapi.vfs.VirtualFile
import com.intellij.openapi.vfs.VirtualFileManager
import com.warrant.phpstorm.lsp.WarrantDeclaration
import com.warrant.phpstorm.lsp.WarrantKind
import com.warrant.phpstorm.lsp.WarrantServerQueries
import com.warrant.phpstorm.lsp.WarrantUsages
import org.eclipse.lsp4j.Location
import org.eclipse.lsp4j.Position
import org.eclipse.lsp4j.Range

/** The library's attributes, as a test project needs them to tell a schema's members apart. */
const val WARRANT_ATTRIBUTES = """
<?php
namespace Warrant\Schema\Abilities {
    interface IDeclaresAbility {}
    #[\Attribute] class Ability implements IDeclaresAbility {}
}
namespace Warrant\Schema\Conditions\Row {
    #[\Attribute] class RowCondition {}
}
namespace Warrant\Schema\RuleTemplates {
    #[\Attribute] class RuleTemplate {}
}
"""

/**
 * Stands in for the language server: finds a declared name's uses by its
 * spelling in the project's files, and says what a name at a position names
 * from what the test told it.
 */
class FakeServer(private val project: Project) : WarrantServerQueries(project) {

    private data class Declared(val file: VirtualFile, val member: String, val kind: WarrantKind, val name: String, val taken: List<String>)

    private val declared = mutableListOf<Declared>()

    /** The files searched for uses, relative to the project's source root. */
    var searched = listOf("resources/documents.warrant", "app/DocumentSchema.php")

    private val positions = mutableMapOf<Pair<VirtualFile, Int>, WarrantDeclaration>()

    fun declare(file: VirtualFile, member: String, kind: WarrantKind, name: String, taken: List<String> = emptyList()) {
        declared += Declared(file, member, kind, name, taken)
    }

    fun at(file: VirtualFile, offset: Int, declaringFile: VirtualFile, member: String, kind: WarrantKind) {
        val name = declared.first { it.file == declaringFile && it.member == member }.name
        positions[file to offset] = WarrantDeclaration(kind.wireValue, name, declaringFile.url, member)
    }

    override fun declarationAt(hostFile: VirtualFile, hostOffset: Int): WarrantDeclaration? {
        positions.entries.firstOrNull { (key, declaration) ->
            key.first == hostFile && hostOffset in key.second..key.second + declaration.name!!.length
        }?.let { return it.value }

        // A name not placed by the test is looked up by its spelling.
        val text = FileDocumentManager.getInstance().getDocument(hostFile)!!.charsSequence
        var start = hostOffset
        while (start > 0 && (text[start - 1].isLetterOrDigit() || text[start - 1] == '_')) start--
        var end = hostOffset
        while (end < text.length && (text[end].isLetterOrDigit() || text[end] == '_')) end++
        val word = text.subSequence(start, end).toString()

        return declared.firstOrNull { it.name == word }?.let { WarrantDeclaration(it.kind.wireValue, it.name, it.file.url, it.member) }
    }

    override fun usagesOf(file: VirtualFile, member: String, notifyIfUnsearched: Boolean): WarrantUsages? {
        val target = declared.firstOrNull { it.file == file && it.member == member } ?: return null
        val pattern = Regex("(?<![A-Za-z0-9_-])${Regex.escape(target.name)}(?![A-Za-z0-9_-])")
        val usages = mutableListOf<Location>()

        for (candidate in searched) {
            val virtualFile = myFixtureFile(candidate) ?: continue
            val document = FileDocumentManager.getInstance().getDocument(virtualFile)!!

            val text = document.charsSequence

            for (match in pattern.findAll(text)) {
                // A string that is the name and nothing else declares it.
                val quoted = match.range.first > 0 && text[match.range.first - 1] == '\'' &&
                    match.range.last + 1 < text.length && text[match.range.last + 1] == '\''
                if (quoted) continue

                usages += Location(virtualFile.url, Range(position(document, match.range.first), position(document, match.range.last + 1)))
            }
        }

        return WarrantUsages(target.kind.wireValue, target.name, usages, target.taken)
    }

    override fun fileOf(uri: String): VirtualFile? = VirtualFileManager.getInstance().findFileByUrl(uri)

    private fun myFixtureFile(path: String): VirtualFile? =
        VirtualFileManager.getInstance().findFileByUrl("temp:///src/$path")

    private fun position(document: Document, offset: Int): Position {
        val line = document.getLineNumber(offset)
        return Position(line, offset - document.getLineStartOffset(line))
    }
}
