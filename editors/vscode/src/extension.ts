import { stat } from 'node:fs/promises'
import * as path from 'node:path'
import * as vscode from 'vscode'
import {
  LanguageClient,
  TransportKind,
  type LanguageClientOptions,
  type ServerOptions,
} from 'vscode-languageclient/node'

/**
 * Where the language server script is looked for, relative to the workspace
 * root, after `warrant.languageServer.path`: the Composer proxy, the
 * script inside the installed package, and the script in the laravel-warrant
 * repository itself.
 */
const SERVER_CANDIDATES = [
  'vendor/bin/warrant-language-server',
  'vendor/patrickhanna/laravel-warrant/bin/warrant-language-server',
  'bin/warrant-language-server',
]

type Vocabulary = 'auto' | 'static' | 'off'

/** The running client and the watcher that feeds it PHP file events. */
interface Session {
  client: LanguageClient
  watcher: vscode.FileSystemWatcher
}

let output: vscode.OutputChannel
let session: Session | undefined

/**
 * Starts and stops run one after another through this chain, so a burst of
 * setting changes never leaves two servers running at once.
 */
let pending: Promise<void> = Promise.resolve()

export function activate(context: vscode.ExtensionContext): void {
  output = vscode.window.createOutputChannel('Warrant Language Server')

  context.subscriptions.push(
    output,
    vscode.commands.registerCommand('warrant.restartLanguageServer', () => restart()),
    vscode.commands.registerCommand('warrant.newFile', () => newWarrantFile()),
    vscode.workspace.onDidChangeConfiguration((event) => {
      if (event.affectsConfiguration('warrant')) {
        void restart()
      }
    }),
    vscode.workspace.onDidGrantWorkspaceTrust(() => restart()),
    vscode.workspace.onDidChangeWorkspaceFolders(() => restart()),
  )

  void enqueue(start)
}

/**
 * File, then New File, then Warrant File: a new untitled file in the Warrant
 * language, which is named when it is first saved.
 */
async function newWarrantFile(): Promise<void> {
  const document = await vscode.workspace.openTextDocument({ language: 'warrant', content: '' })
  await vscode.window.showTextDocument(document)
}

export function deactivate(): Promise<void> {
  return enqueue(stop)
}

function restart(): Promise<void> {
  return enqueue(async () => {
    await stop()
    await start()
  })
}

function enqueue(task: () => Promise<void>): Promise<void> {
  pending = pending.then(task).catch((error: unknown) => {
    log(`Error: ${describe(error)}`)
  })
  return pending
}

async function start(): Promise<void> {
  const config = vscode.workspace.getConfiguration('warrant')

  if (!config.get<boolean>('languageServer.enabled', true)) {
    log('The language server is disabled by warrant.languageServer.enabled.')
    return
  }

  // The server runs PHP from the workspace, and in `auto` vocabulary boots the
  // application itself, so an untrusted workspace gets highlighting only.
  if (!vscode.workspace.isTrusted) {
    log('The workspace is not trusted, so the language server is not started.')
    return
  }

  const folder = vscode.workspace.workspaceFolders?.[0]
  if (folder?.uri.scheme !== 'file') {
    log('No local workspace folder is open, so the language server is not started.')
    return
  }

  const root = folder.uri.fsPath
  const serverPath = await findServer(root, config.get<string>('languageServer.path', ''))
  if (serverPath === undefined) {
    log(
      `No warrant-language-server script was found under ${root}. ` +
        'Install laravel-warrant with Composer, or set warrant.languageServer.path.',
    )
    return
  }

  const php = config.get<string>('languageServer.phpPath', 'php').trim() || 'php'
  const vocabulary = config.get<Vocabulary>('vocabulary', 'auto')

  // The script is always handed to PHP, never executed itself: the Composer
  // proxy in vendor/bin is a PHP file, and that is the one form that runs the
  // same way on every platform.
  const serverOptions: ServerOptions = {
    command: php,
    args: [serverPath],
    transport: TransportKind.stdio,
    options: { cwd: root },
  }

  const watcher = vscode.workspace.createFileSystemWatcher('**/*.php')

  const clientOptions: LanguageClientOptions = {
    documentSelector: [
      { scheme: 'file', language: 'warrant' },
      { scheme: 'file', language: 'php' },
    ],
    synchronize: { fileEvents: watcher },
    initializationOptions: { vocabulary },
    outputChannel: output,
    workspaceFolder: folder,
  }

  const client = new LanguageClient(
    'warrant',
    'Warrant Language Server',
    serverOptions,
    clientOptions,
  )

  session = { client, watcher }
  log(`Starting ${php} ${serverPath} (vocabulary: ${vocabulary}).`)

  try {
    await client.start()
  } catch (error) {
    log(`The language server failed to start: ${describe(error)}`)
    await stop()
  }
}

async function stop(): Promise<void> {
  const current = session
  session = undefined
  if (current === undefined) {
    return
  }

  try {
    await current.client.dispose()
  } catch (error) {
    log(`The language server did not stop cleanly: ${describe(error)}`)
  } finally {
    current.watcher.dispose()
  }
}

/**
 * Returns the absolute path of the first server script that exists, trying the
 * configured path before the conventional locations.
 */
async function findServer(root: string, configured: string): Promise<string | undefined> {
  const candidates = configured.trim() === '' ? SERVER_CANDIDATES : [configured.trim(), ...SERVER_CANDIDATES]

  for (const candidate of candidates) {
    const absolute = path.resolve(root, candidate)
    if (await isFile(absolute)) {
      return absolute
    }
  }

  return undefined
}

async function isFile(file: string): Promise<boolean> {
  try {
    return (await stat(file)).isFile()
  } catch {
    return false
  }
}

function log(message: string): void {
  output.appendLine(`[${new Date().toLocaleTimeString()}] ${message}`)
}

function describe(error: unknown): string {
  return error instanceof Error ? error.message : String(error)
}
