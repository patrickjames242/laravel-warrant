package com.warrant.phpstorm

import junit.framework.TestCase

/**
 * A rule-text name and its method's name turn into each other exactly, so the
 * method name given for a rule-text name gives that name back.
 */
class WarrantNamesTest : TestCase() {

    fun testTakesARuleTextNameFromAMethodNameAsStrSnakeDoes() {
        assertEquals("is_owner", WarrantNames.ruleTextNameOf("isOwner"))
        assertEquals("owner_or_team", WarrantNames.ruleTextNameOf("ownerOrTeam"))
        assertEquals("is_o_k", WarrantNames.ruleTextNameOf("isOK"))
        assertEquals("already_snake", WarrantNames.ruleTextNameOf("already_snake"))
        assertEquals("is2_fa", WarrantNames.ruleTextNameOf("is2Fa"))
    }

    fun testGivesTheMethodNameWhoseRuleTextNameIsTheOneAskedFor() {
        assertEquals("grantedByPayrollGroup", WarrantNames.methodNameFor("granted_by_payroll_group"))
        assertEquals("grantedByPayrollGroup_123", WarrantNames.methodNameFor("granted_by_payroll_group_123"))
        assertEquals("is_Owner", WarrantNames.methodNameFor("is__owner"))
        assertEquals("_owner", WarrantNames.methodNameFor("_owner"))
        assertEquals("view", WarrantNames.methodNameFor("view"))
    }

    fun testGivesNoMethodNameForANameNoMethodNameGives() {
        assertNull(WarrantNames.methodNameFor("posts-modify"))
        assertNull(WarrantNames.methodNameFor("Owner"))
    }

    fun testEveryMethodNameGivenGivesItsNameBack() {
        for (name in listOf("a_b_c", "x_1_y", "a__b", "abc_", "a_1", "q_r_s_t")) {
            val method = WarrantNames.methodNameFor(name)
            assertNotNull(name, method)
            assertEquals(name, WarrantNames.ruleTextNameOf(method!!))
        }
    }

    fun testReadsANameAsRuleTextDoes() {
        assertTrue(WarrantNames.isName("is_owner"))
        assertTrue(WarrantNames.isName("posts-modify"))
        assertFalse(WarrantNames.isName("or"))
        assertFalse(WarrantNames.isName("two words"))
        assertFalse(WarrantNames.isName(""))
    }
}
