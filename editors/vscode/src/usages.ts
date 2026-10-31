import * as vscode from 'vscode'
import type { Location as ProtocolLocation } from 'vscode-languageclient'
import type { LanguageClient } from 'vscode-languageclient/node'
import { isName, methodNameFor } from './names'
import { declarationSite, escape, memberAt, membersOf, positionIn } from './php'

/**
 * Finding the usages of conditions, templates, abilities, schemas, context keys
 * and frame names across the project's rule text, and renaming conditions,
 * templates and frame names with their uses, through the language server's
 * `warrant/declaration`, `warrant/usages` and `warrant/occurrences`.
 *
 * Every use comes from the server, which looks each name up as validation
 * does, so an `is_owner` that belongs to another schema is left alone.
 *
 * Rename (F2) is VS Code's, and VS Code asks one rename provider at a time,
 * moving to the next only when one declines. On a name in rule text, a key
 * given to an attribute or a frame name, this one answers. On a method's name
 * it declines, so the PHP extension renames the method as PHP; the command
 * **Warrant: Rename Condition or Template** renames a condition or template from
 * there with its uses in rule text.
 */

/** The condition, template or ability a name in rule text names, and what declares it. */
interface Declaration {
  kind: string
  name: string
  uri: string
  member: string
}

/**
 * Where a declaration is used in rule text, and the names its kind already has
 * in each schema that has it, which a rename must not take.
 */
interface Usages {
  kind: string
  name: string
  usages: ProtocolLocation[]
  taken: string[]
}

/** A context key or frame name, and every place it is written. */
interface Occurrences {
  kind: 'context' | 'frame'
  name: string
  occurrences: ProtocolLocation[]
}

/**
 * What a position is on: something PHP declares, with where it is used, or a
 * name no PHP declares, with where it is written. `range` is the name at the
 * position, in rule text or in the PHP that declares it.
 */
type Target =
  | { type: 'declared'; declaration: Declaration; usages: Usages; range: vscode.Range }
  | { type: 'written'; occurrences: Occurrences; range: vscode.Range }

const SELECTOR: vscode.DocumentSelector = [
  { scheme: 'file', language: 'warrant' },
  { scheme: 'file', language: 'php' },
]

const PHP: vscode.DocumentSelector = [{ scheme: 'file', language: 'php' }]

/** A name in rule text, for the range of one the server gives no location of. */
const RULE_TEXT_NAME = /[A-Za-z_][\w-]*/

/** Warrant's builder methods taking a condition's name first, as in `->if('is_owner')`. */
const CONDITION_METHODS = ['if', 'andIf', 'orIf', 'ifNot', 'andIfNot', 'orIfNot']

/** Where the project's PHP files are not searched for builder strings, as the server does not search them for rule text. */
const UNSEARCHED = '{**/vendor/**,**/node_modules/**,**/storage/**,**/.git/**}'

/**
 * Registers the providers and the command. `clientOf` gives the running
 * client, or undefined while none runs, when each provider answers nothing.
 * The returned function has the usage counts asked again, as once a server
 * has started.
 */
export function registerUsages(context: vscode.ExtensionContext, clientOf: () => LanguageClient | undefined): () => void {
  const lensesChanged = new vscode.EventEmitter<void>()
  let quiet: NodeJS.Timeout | undefined

  context.subscriptions.push(
    lensesChanged,
    vscode.languages.registerReferenceProvider(SELECTOR, {
      provideReferences: (document, position, options, token) => {
        const client = clientOf()
        return client === undefined ? undefined : references(client, document, position, options.includeDeclaration, token)
      },
    }),
    vscode.languages.registerRenameProvider(SELECTOR, {
      prepareRename: async (document, position, token) => {
        const client = clientOf()
        const target = client === undefined ? undefined : await renamable(client, document, position, token)
        return target === undefined ? undefined : { range: target.range, placeholder: document.getText(target.range) }
      },
      provideRenameEdits: async (document, position, newName, token) => {
        const client = clientOf()
        const target = client === undefined ? undefined : await renamable(client, document, position, token)
        return client === undefined || target === undefined ? undefined : renameEdits(client, target, newName)
      },
    }),
    vscode.languages.registerDocumentHighlightProvider(SELECTOR, {
      provideDocumentHighlights: async (document, position, token) => {
        const client = clientOf()
        const found = client === undefined ? null : await occurrencesAt(client, document, position, token)
        return client === undefined || found === null
          ? undefined
          : found.occurrences
            .map((location) => client.protocol2CodeConverter.asLocation(location))
            .filter((location) => location.uri.toString() === document.uri.toString())
            .map((location) => new vscode.DocumentHighlight(location.range, vscode.DocumentHighlightKind.Text))
      },
    }),
    vscode.languages.registerCodeLensProvider(PHP, {
      onDidChangeCodeLenses: lensesChanged.event,
      provideCodeLenses: (document, token) => {
        const client = clientOf()
        return client === undefined ? undefined : usageLenses(client, document, token)
      },
    }),
    vscode.commands.registerCommand('warrant.renameDeclaration', () => renameFromCommand(clientOf())),
    vscode.workspace.onDidChangeTextDocument((event) => {
      if (['warrant', 'php'].includes(event.document.languageId) && event.contentChanges.length > 0) {
        clearTimeout(quiet)
        quiet = setTimeout(() => { lensesChanged.fire() }, 750)
      }
    }),
    { dispose: () => { clearTimeout(quiet) } },
  )

  return () => { lensesChanged.fire() }
}

/** Every use of what the position is on, and with `includeDeclaration` where PHP declares it. */
async function references(
  client: LanguageClient,
  document: vscode.TextDocument,
  position: vscode.Position,
  includeDeclaration: boolean,
  token: vscode.CancellationToken,
): Promise<vscode.Location[] | undefined> {
  const target = await targetAt(client, document, position, token, true)

  if (target === undefined) {
    return undefined
  }

  if (target.type === 'written') {
    return target.occurrences.occurrences.map((location) => client.protocol2CodeConverter.asLocation(location))
  }

  const locations = target.usages.usages.map((location) => client.protocol2CodeConverter.asLocation(location))
  const declared = includeDeclaration ? await siteOf(client, target.declaration) : undefined

  return declared === undefined ? locations : [declared.location, ...locations]
}

/**
 * What a rename at the position renames, when this provider renames it: a
 * condition or template from rule text or from its key, or a frame name.
 * Undefined where it leaves the rename to another provider, on PHP's own
 * names. Throws with the reason where nothing renames what is there.
 */
async function renamable(
  client: LanguageClient,
  document: vscode.TextDocument,
  position: vscode.Position,
  token: vscode.CancellationToken,
): Promise<Target | undefined> {
  const target = await targetAt(client, document, position, token, false)

  if (target !== undefined) {
    refusal(target)
  }

  return target
}

/** Throws with the reason a rename of `target` is refused, when it is. */
function refusal(target: Target): void {
  if (target.type === 'written') {
    if (target.occurrences.kind === 'context') {
      throw new Error('A context key is not renamed: the PHP that gives a check its context names it too.')
    }
    return
  }

  if (target.declaration.kind === 'ability') {
    throw new Error('An ability is not renamed with its uses in rule text.')
  }

  if (target.declaration.kind === 'schema') {
    throw new Error("Rule text names a schema by its key, which is not renamed.")
  }
}

/**
 * What the position is on. `withPhpNames` takes a PHP method, constant or
 * class name as naming what it declares; without it, those are left to PHP.
 */
async function targetAt(
  client: LanguageClient,
  document: vscode.TextDocument,
  position: vscode.Position,
  token: vscode.CancellationToken,
  withPhpNames: boolean,
): Promise<Target | undefined> {
  const member = document.languageId === 'php' ? memberAt(document, position) : undefined

  if (member !== undefined) {
    const isPhpName = member.kind === 'method' || member.kind === 'constant' || member.kind === 'class'

    if (isPhpName && !withPhpNames) {
      return undefined
    }

    const uri = client.code2ProtocolConverter.asUri(document.uri)
    const usages = await usagesOf(client, uri, member.member, token)

    if (usages !== null && (isPhpName || usages.name === member.text)) {
      return {
        type: 'declared',
        declaration: { kind: usages.kind, name: usages.name, uri, member: member.member },
        usages,
        range: member.range,
      }
    }

    if (isPhpName) {
      return undefined
    }
  }

  const params = {
    textDocument: { uri: client.code2ProtocolConverter.asUri(document.uri) },
    position: client.code2ProtocolConverter.asPosition(position),
  }
  const declaration = await client.sendRequest<Declaration | null>('warrant/declaration', params, token)

  if (declaration !== null) {
    const usages = await usagesOf(client, declaration.uri, declaration.member, token)

    return usages === null
      ? undefined
      : { type: 'declared', declaration, usages, range: rangeAt(client, document, position, usages.usages) }
  }

  const occurrences = await occurrencesAt(client, document, position, token)

  return occurrences === null
    ? undefined
    : { type: 'written', occurrences, range: rangeAt(client, document, position, occurrences.occurrences) }
}

function usagesOf(client: LanguageClient, uri: string, member: string, token?: vscode.CancellationToken): Promise<Usages | null> {
  return client.sendRequest<Usages | null>('warrant/usages', { uri, member }, token)
}

function occurrencesAt(
  client: LanguageClient,
  document: vscode.TextDocument,
  position: vscode.Position,
  token: vscode.CancellationToken,
): Promise<Occurrences | null> {
  return client.sendRequest<Occurrences | null>('warrant/occurrences', {
    textDocument: { uri: client.code2ProtocolConverter.asUri(document.uri) },
    position: client.code2ProtocolConverter.asPosition(position),
  }, token)
}

/** The one of `locations` in `document` holding `position`, or the name written there. */
function rangeAt(client: LanguageClient, document: vscode.TextDocument, position: vscode.Position, locations: ProtocolLocation[]): vscode.Range {
  const here = locations
    .map((location) => client.protocol2CodeConverter.asLocation(location))
    .find((location) => location.uri.toString() === document.uri.toString() && location.range.contains(position))

  return here?.range ?? document.getWordRangeAtPosition(position, RULE_TEXT_NAME) ?? new vscode.Range(position, position)
}

/** Where PHP writes the name of `declaration`, with the document it is in. */
async function siteOf(client: LanguageClient, declaration: Declaration): Promise<{ document: vscode.TextDocument; kind: string; location: vscode.Location } | undefined> {
  const document = await vscode.workspace.openTextDocument(client.protocol2CodeConverter.asUri(declaration.uri))
  const site = declarationSite(document, declaration.kind, declaration.member, declaration.name)

  return site === undefined ? undefined : { document, kind: site.kind, location: new vscode.Location(document.uri, site.range) }
}

/** The edit renaming `target` to `newName`. Throws with the reason a name is refused. */
async function renameEdits(client: LanguageClient, target: Target, newName: string): Promise<vscode.WorkspaceEdit> {
  refusal(target)

  const site = target.type === 'declared' ? await siteOf(client, target.declaration) : undefined
  const problem = problemWith(target, newName, site?.kind === 'key')
  if (problem !== undefined) {
    throw new Error(problem)
  }

  const edit = new vscode.WorkspaceEdit()

  if (target.type === 'written') {
    for (const location of target.occurrences.occurrences) {
      edit.replace(client.protocol2CodeConverter.asUri(location.uri), client.protocol2CodeConverter.asRange(location.range), newName)
    }
    return edit
  }

  const { declaration, usages } = target

  if (newName === usages.name) {
    return edit
  }

  if (site === undefined) {
    throw new Error(`The ${declaration.kind} '${usages.name}' is declared by ${declaration.member}, which was not found in its file.`)
  }

  if (site.kind === 'key') {
    edit.replace(site.location.uri, site.location.range, newName)
  } else {
    await renameMethod(edit, site.location, methodNameFor(newName) ?? '')
  }

  for (const location of usages.usages) {
    edit.replace(client.protocol2CodeConverter.asUri(location.uri), client.protocol2CodeConverter.asRange(location.range), newName)
  }

  if (declaration.kind === 'condition') {
    await offerBuilderStrings(edit, usages.name, newName)
  }

  return edit
}

/**
 * Why `newName` cannot be given to `target`, or undefined when it can.
 * `keyed` is whether a key given to its attribute names it, which takes any
 * name rule text can write; a method takes one only when a method name has it.
 */
function problemWith(target: Target, newName: string, keyed: boolean): string | undefined {
  if (!isName(newName)) {
    return `Rule text cannot write '${newName}' as a name.`
  }

  if (target.type === 'declared') {
    if (target.usages.taken.includes(newName)) {
      return `A schema that has this ${target.declaration.kind} already has one named '${newName}'.`
    }

    if (!keyed && methodNameFor(newName) === undefined) {
      return `No PHP method has the name '${newName}' in rule text, so the method cannot take it.`
    }
  }

  return undefined
}

/**
 * Adds the renaming of the method declared at `declared` to `methodName`, with
 * PHP's own uses of it as the PHP extension renames them. This provider
 * declines a method's name, so asking VS Code for the rename there reaches
 * the PHP extension. Without one, the method alone is renamed.
 */
async function renameMethod(edit: vscode.WorkspaceEdit, declared: vscode.Location, methodName: string): Promise<void> {
  let php: vscode.WorkspaceEdit | undefined

  try {
    php = await vscode.commands.executeCommand<vscode.WorkspaceEdit | undefined>(
      'vscode.executeDocumentRenameProvider',
      declared.uri,
      declared.range.start,
      methodName,
    )
  } catch {
    php = undefined
  }

  let renamesDeclaration = false

  for (const [uri, edits] of php?.entries() ?? []) {
    for (const textEdit of edits) {
      renamesDeclaration ||= uri.toString() === declared.uri.toString() && textEdit.range.intersection(declared.range) !== undefined
      edit.replace(uri, textEdit.range, textEdit.newText)
    }
  }

  if (!renamesDeclaration) {
    edit.replace(declared.uri, declared.range, methodName)
  }
}

/**
 * Adds, each needing confirmation and so unticked in the refactor preview, the
 * project's PHP strings that may name the condition `oldName` but that no
 * schema ties to it: the names given first to Warrant's builders, as in
 * `->if('is_owner')` and `Build::check('is_owner')`.
 */
async function offerBuilderStrings(edit: vscode.WorkspaceEdit, oldName: string, newName: string): Promise<void> {
  const pattern = new RegExp(
    String.raw`(?:\??->\s*(?:${CONDITION_METHODS.join('|')})|\bBuild\s*::\s*check)\s*\(\s*(['"])${escape(oldName)}\1`,
    'g',
  )
  const metadata: vscode.WorkspaceEditEntryMetadata = {
    needsConfirmation: true,
    label: `Strings that may name '${oldName}'`,
    description: 'Nothing ties these to a schema. Tick the ones that mean the condition renamed.',
  }
  const open = new Map(vscode.workspace.textDocuments.map((document) => [document.uri.toString(), document]))

  for (const uri of await vscode.workspace.findFiles('**/*.php', UNSEARCHED)) {
    const document = open.get(uri.toString())
    const text = document?.getText() ?? new TextDecoder().decode(await vscode.workspace.fs.readFile(uri))

    if (!text.includes(oldName)) {
      continue
    }

    for (const match of text.matchAll(pattern)) {
      const start = match.index + match[0].length - oldName.length - 1
      const range = document === undefined
        ? new vscode.Range(positionIn(text, start), positionIn(text, start + oldName.length))
        : new vscode.Range(document.positionAt(start), document.positionAt(start + oldName.length))

      edit.replace(uri, range, newName, metadata)
    }
  }
}

/**
 * **Warrant: Rename Condition or Template**: renames what the cursor is on with
 * its uses in rule text, from anywhere a rename can start, a method's name
 * among them. It asks for the name rule text writes, `is_author`, and a method
 * takes it in camel case.
 */
async function renameFromCommand(client: LanguageClient | undefined): Promise<void> {
  const editor = vscode.window.activeTextEditor

  if (client === undefined) {
    void vscode.window.showWarningMessage('The Warrant language server is not running.')
    return
  }

  if (editor === undefined) {
    return
  }

  const cancel = new vscode.CancellationTokenSource()
  let target: Target | undefined

  try {
    target = await targetAt(client, editor.document, editor.selection.active, cancel.token, true)
    if (target !== undefined) {
      refusal(target)
    }
  } catch (error) {
    void vscode.window.showInformationMessage(error instanceof Error ? error.message : String(error))
    return
  } finally {
    cancel.dispose()
  }

  if (target === undefined) {
    void vscode.window.showInformationMessage('Put the cursor on a condition, template or frame name, or on what declares one.')
    return
  }

  const name = target.type === 'declared' ? target.usages.name : target.occurrences.name
  const what = target.type === 'declared' ? target.declaration.kind : 'frame'
  const chosen = target
  const keyed = target.type === 'declared' && (await siteOf(client, target.declaration))?.kind === 'key'
  const newName = await vscode.window.showInputBox({
    title: `Rename ${what} '${name}'`,
    prompt: `Rename the ${what} '${name}' and its uses in rule text to the name rule text writes`,
    value: name,
    validateInput: (value) => problemWith(chosen, value, keyed),
  })

  if (newName === undefined || newName === name) {
    return
  }

  try {
    await vscode.workspace.applyEdit(await renameEdits(client, chosen, newName))
  } catch (error) {
    void vscode.window.showErrorMessage(error instanceof Error ? error.message : String(error))
  }
}

/** A count of its uses in rule text above each member of `document` that declares something. */
async function usageLenses(client: LanguageClient, document: vscode.TextDocument, token: vscode.CancellationToken): Promise<vscode.CodeLens[]> {
  const uri = client.code2ProtocolConverter.asUri(document.uri)
  const lenses = await Promise.all(membersOf(document).map(async (member) => {
    const usages = await usagesOf(client, uri, member.member, token).catch(() => null)

    if (usages === null) {
      return undefined
    }

    const count = usages.usages.length
    const locations = usages.usages.map((location) => client.protocol2CodeConverter.asLocation(location))

    return new vscode.CodeLens(member.range, {
      title: count === 0 ? 'no uses in rule text' : `${String(count)} ${count === 1 ? 'use' : 'uses'} in rule text`,
      command: count === 0 ? '' : 'editor.action.showReferences',
      arguments: count === 0 ? [] : [document.uri, member.range.start, locations],
    })
  }))

  return lenses.filter((lens) => lens !== undefined)
}
