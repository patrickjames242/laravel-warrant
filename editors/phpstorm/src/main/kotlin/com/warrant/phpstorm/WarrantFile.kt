package com.warrant.phpstorm

import com.intellij.extapi.psi.PsiFileBase
import com.intellij.openapi.fileTypes.FileType
import com.intellij.psi.FileViewProvider

/** The PSI root for a Warrant document (a `.warrant` file or an injected heredoc). */
class WarrantFile(viewProvider: FileViewProvider) : PsiFileBase(viewProvider, WarrantLanguage) {
    override fun getFileType(): FileType = WarrantFileType.INSTANCE
    override fun toString(): String = "Warrant File"
}
