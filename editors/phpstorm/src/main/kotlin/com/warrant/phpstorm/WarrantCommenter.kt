package com.warrant.phpstorm

import com.intellij.lang.Commenter

/**
 * Comment Line comments Warrant out with `#`, the only comment the language has.
 */
class WarrantCommenter : Commenter {

    override fun getLineCommentPrefix(): String = "# "

    override fun getBlockCommentPrefix(): String? = null

    override fun getBlockCommentSuffix(): String? = null

    override fun getCommentedBlockCommentPrefix(): String? = null

    override fun getCommentedBlockCommentSuffix(): String? = null
}
