/**
 * Every outbound link the home page makes. The docs pages these point at live
 * on the current site until this project has pages of its own.
 */
const DOCS = 'https://laravel-warrant.dev'

export const links = {
  github: 'https://github.com/patrickjames242/laravel-warrant',
  issues: 'https://github.com/patrickjames242/laravel-warrant/issues',
  whyWarrant: `${DOCS}/getting-started/why-warrant/`,
  installation: `${DOCS}/getting-started/installation/`,
  quickStart: `${DOCS}/getting-started/quick-start/`,
  coreConcepts: `${DOCS}/getting-started/core-concepts/`,
  vsSpatie: `${DOCS}/getting-started/vs-spatie-laravel-permission/`,
  guides: `${DOCS}/guides/schemas/`,
  ruleLanguage: `${DOCS}/guides/rule-language/`,
  resolvers: `${DOCS}/guides/resolvers/`,
  checkingAccess: `${DOCS}/guides/checking-access/`,
  howItCompiles: `${DOCS}/guides/how-it-compiles/`,
  cheatSheet: `${DOCS}/reference/api-cheat-sheet/`,
  errors: `${DOCS}/reference/errors/`,
} as const

export const INSTALL_COMMAND = 'composer require patrickhanna/laravel-warrant'
