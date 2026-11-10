package com.warrant.phpstorm.lsp

import com.intellij.openapi.project.Project
import com.intellij.openapi.vfs.VirtualFile
import com.intellij.psi.PsiElement
import com.intellij.psi.PsiManager
import com.intellij.psi.util.PsiTreeUtil
import com.jetbrains.php.PhpIndex
import com.jetbrains.php.lang.psi.elements.Field
import com.jetbrains.php.lang.psi.elements.Method
import com.jetbrains.php.lang.psi.elements.PhpAttribute
import com.jetbrains.php.lang.psi.elements.PhpClass
import com.jetbrains.php.lang.psi.elements.StringLiteralExpression
import com.warrant.phpstorm.WarrantNames

/** What a name in rule text can name and the PHP declares. */
enum class WarrantKind(val wireValue: String, val label: String) {
    CONDITION("condition", "Warrant condition"),
    TEMPLATE("template", "Warrant template"),
    RELATION("relation", "Warrant row relation"),
    ABILITY("ability", "Warrant ability"),
    SCHEMA("schema", "Warrant schema");

    companion object {
        fun of(wireValue: String?): WarrantKind? = entries.firstOrNull { it.wireValue == wireValue }
    }
}

/**
 * A condition, template or ability, as the PHP declares it.
 *
 * @property kind What it is.
 * @property file The file it is declared in.
 * @property member The method or constant that declares it, or a schema's
 *   class, which with [file] is how the language server knows it.
 * @property nameDeclaredBy What writes its name in the PHP: the method, whose
 *   name in snake case is the name rule text uses, the string that is the
 *   name, a key given to the attribute or an ability constant's value, or a
 *   schema's class, whose key rule text uses.
 */
data class WarrantConstruct(
    val kind: WarrantKind,
    val file: VirtualFile,
    val member: String,
    val nameDeclaredBy: PsiElement,
) {
    /**
     * Whether a rename renames it with its uses in rule text: a condition,
     * template or row relation does. An ability and a schema never do, so
     * renaming an ability constant's value changes only the string, and renaming
     * a schema's class leaves its key, which rule text names it by.
     */
    val isRenamable: Boolean
        get() = kind == WarrantKind.CONDITION || kind == WarrantKind.TEMPLATE || kind == WarrantKind.RELATION

    /** The name rule text uses for it once [nameDeclaredBy] is renamed to [newName]. */
    fun nameAfterRename(newName: String): String =
        if (nameDeclaredBy is Method) WarrantNames.ruleTextNameOf(newName) else newName
}

/**
 * Finds the conditions, templates, row relations and abilities the PHP
 * declares: a method carrying a condition, template or row relation attribute,
 * and a class constant carrying an ability attribute, whose value is the
 * ability's name.
 */
object WarrantConstructs {

    private val CONDITION_ATTRIBUTES = setOf(
        "\\Warrant\\Schema\\Conditions\\Row\\RowCondition",
        "\\Warrant\\Schema\\Conditions\\Global\\GlobalCondition",
        "\\Warrant\\Schema\\Conditions\\Derived\\DerivedCondition",
    )

    private const val TEMPLATE_ATTRIBUTE = "\\Warrant\\Schema\\RuleTemplates\\RuleTemplate"

    /** Names its schema first, so its key by position is its second argument. */
    private const val RELATION_ATTRIBUTE = "\\Warrant\\Schema\\Relations\\RowRelation"

    /** What every schema class implements. */
    private const val SCHEMA_INTERFACE = "\\Warrant\\Schema\\Types\\Interfaces\\ISchema"

    /** What every ability attribute implements, the library's `#[Ability]` among them. */
    private const val ABILITY_ATTRIBUTE = "\\Warrant\\Schema\\Abilities\\IDeclaresAbility"

    /**
     * The construct whose name [element] declares: a condition or template
     * method with no key given, the key given to its attribute, or an ability
     * constant's string value. Null for anything else, an ability constant
     * whose value is computed among them, since no string of its own holds the
     * name.
     */
    fun declaredBy(element: PsiElement): WarrantConstruct? {
        val file = element.containingFile?.virtualFile ?: return null

        return when (element) {
            is Method -> {
                val kind = kindOf(element) ?: return null
                if (keyOf(element) != null) null else WarrantConstruct(kind, file, element.name, element)
            }

            is PhpClass ->
                if (implementsInterface(element, SCHEMA_INTERFACE, mutableSetOf())) {
                    WarrantConstruct(WarrantKind.SCHEMA, file, element.fqn.trimStart('\\'), element)
                } else {
                    null
                }

            is StringLiteralExpression -> {
                keyedMethodOf(element)?.let { method ->
                    WarrantConstruct(kindOf(method) ?: return null, file, method.name, element)
                } ?: abilityConstantOf(element)?.let { constant ->
                    WarrantConstruct(WarrantKind.ABILITY, file, constant.name, element)
                }
            }

            else -> null
        }
    }

    /**
     * What the PHP declaring [declaration] is: the string or method
     * [declaredBy] takes it from, or for an ability whose value is computed,
     * the constant.
     */
    fun declaringElement(project: Project, declaration: WarrantDeclaration): PsiElement? {
        val kind = WarrantKind.of(declaration.kind) ?: return null
        val member = declaration.member ?: return null
        val file = WarrantServerQueries.getInstance(project).fileOf(declaration.uri ?: return null) ?: return null
        val psiFile = PsiManager.getInstance(project).findFile(file) ?: return null

        if (kind == WarrantKind.ABILITY) {
            val constant = PsiTreeUtil.findChildrenOfType(psiFile, Field::class.java)
                .firstOrNull { it.isConstant && it.name == member }
                ?: return null

            return constant.defaultValue as? StringLiteralExpression ?: constant
        }

        val method = PsiTreeUtil.findChildrenOfType(psiFile, Method::class.java)
            .firstOrNull { it.name.equals(member, ignoreCase = true) && kindOf(it) == kind }
            ?: return null

        return keyOf(method) ?: method
    }

    /** Whether [method] declares a condition, template or row relation, and which. */
    fun kindOf(method: Method): WarrantKind? = when {
        method.attributes.any { it.fqn in CONDITION_ATTRIBUTES } -> WarrantKind.CONDITION
        method.attributes.any { it.fqn == TEMPLATE_ATTRIBUTE } -> WarrantKind.TEMPLATE
        method.attributes.any { it.fqn == RELATION_ATTRIBUTE } -> WarrantKind.RELATION
        else -> null
    }

    /**
     * The key [method]'s condition, template or row relation attribute is given,
     * when it is given as a string: by the `key` name, or by position.
     */
    fun keyOf(method: Method): StringLiteralExpression? {
        val attribute = method.attributes.firstOrNull {
            it.fqn in CONDITION_ATTRIBUTES || it.fqn == TEMPLATE_ATTRIBUTE || it.fqn == RELATION_ATTRIBUTE
        } ?: return null

        return keyArgumentOf(attribute) as? StringLiteralExpression
    }

    /** The method whose attribute [literal] is the key argument of. */
    private fun keyedMethodOf(literal: StringLiteralExpression): Method? {
        val attribute = PsiTreeUtil.getParentOfType(literal, PhpAttribute::class.java) ?: return null
        val method = attribute.owner as? Method ?: return null

        return if (keyArgumentOf(attribute) === literal && kindOf(method) != null) method else null
    }

    /**
     * The argument [attribute] is given its key by: by the `key` name, or by
     * position, which is first, or second for a row relation.
     */
    private fun keyArgumentOf(attribute: PhpAttribute): PsiElement? =
        attribute.getParameter("key", if (attribute.fqn == RELATION_ATTRIBUTE) 1 else 0)

    /** The ability constant [literal] is the value of. */
    private fun abilityConstantOf(literal: StringLiteralExpression): Field? {
        val constant = literal.parent as? Field ?: return null

        return if (constant.defaultValue === literal && isAbilityConstant(constant)) constant else null
    }

    /** Whether [field] is a class constant carrying an ability attribute. */
    fun isAbilityConstant(field: Field): Boolean {
        if (!field.isConstant) return false

        val index = PhpIndex.getInstance(field.project)

        return field.attributes.any { attribute ->
            val fqn = attribute.fqn ?: return@any false
            index.getClassesByFQN(fqn).any { implementsInterface(it, ABILITY_ATTRIBUTE, mutableSetOf()) }
        }
    }

    /** Whether [phpClass] is, extends or implements [interfaceFqn], at any remove. */
    private fun implementsInterface(phpClass: PhpClass, interfaceFqn: String, seen: MutableSet<String>): Boolean {
        if (!seen.add(phpClass.fqn)) return false
        if (phpClass.fqn == interfaceFqn) return true

        return (listOfNotNull(phpClass.superClass) + phpClass.implementedInterfaces).any { implementsInterface(it, interfaceFqn, seen) }
    }
}
