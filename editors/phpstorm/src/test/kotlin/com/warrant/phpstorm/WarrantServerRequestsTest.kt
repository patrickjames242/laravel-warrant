package com.warrant.phpstorm

import com.warrant.phpstorm.lsp.WarrantDeclaration
import com.warrant.phpstorm.lsp.WarrantLanguageServer
import com.warrant.phpstorm.lsp.WarrantUsages
import junit.framework.TestCase
import org.eclipse.lsp4j.jsonrpc.json.MessageJsonHandler
import org.eclipse.lsp4j.jsonrpc.messages.ResponseMessage
import org.eclipse.lsp4j.jsonrpc.services.ServiceEndpoints

/**
 * The client reads the server's answers to `warrant/declaration` and
 * `warrant/usages` as the server writes them.
 */
class WarrantServerRequestsTest : TestCase() {

    private val handler = MessageJsonHandler(ServiceEndpoints.getSupportedMethods(WarrantLanguageServer::class.java))

    /** The result of a response to [method], as the client reads it. */
    private fun read(method: String, result: String): Any? {
        handler.methodProvider = org.eclipse.lsp4j.jsonrpc.json.MethodProvider { method }

        return (handler.parseMessage("""{"jsonrpc":"2.0","id":"1","result":$result}""") as ResponseMessage).result
    }

    fun testReadsADeclaration() {
        val declaration = read(
            "warrant/declaration",
            """{"kind":"condition","name":"in_team","uri":"file:///app/Schemas/ChecksTeams.php","member":"inTeam"}""",
        ) as WarrantDeclaration

        assertEquals(WarrantDeclaration("condition", "in_team", "file:///app/Schemas/ChecksTeams.php", "inTeam"), declaration)
    }

    fun testReadsUsages() {
        val usages = read(
            "warrant/usages",
            """{"kind":"ability","name":"edit","usages":[{"uri":"file:///rules.warrant","range":{"start":{"line":1,"character":27},"end":{"line":1,"character":31}}}],"taken":["archive","view"]}""",
        ) as WarrantUsages

        assertEquals("edit", usages.name)
        assertEquals(27, usages.usages!!.single().range.start.character)
        assertEquals("file:///rules.warrant", usages.usages!!.single().uri)
        assertEquals(listOf("archive", "view"), usages.taken)
    }

    fun testReadsNoAnswer() {
        assertNull(read("warrant/usages", "null"))
    }
}
