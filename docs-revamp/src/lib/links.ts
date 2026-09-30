import { pagePath } from '../docs/nav'

/** Every link the site makes outside a docs page's own content. */
export const links = {
  github: 'https://github.com/patrickjames242/laravel-warrant',
  issues: 'https://github.com/patrickjames242/laravel-warrant/issues',
  whyWarrant: pagePath('getting-started/why-warrant'),
  installation: pagePath('getting-started/installation'),
  quickStart: pagePath('getting-started/quick-start'),
  coreConcepts: pagePath('getting-started/core-concepts'),
  vsSpatie: pagePath('getting-started/vs-spatie-laravel-permission'),
  guides: pagePath('schemas/anatomy'),
  ruleLanguage: pagePath('rules/basics'),
  resolvers: pagePath('supplying-rules/resolver'),
  checkingAccess: pagePath('checking/ways-in'),
  howItCompiles: pagePath('sql/rule-to-query'),
  cheatSheet: pagePath('reference/cheat-sheet'),
  errors: pagePath('diagnosis/errors'),
} as const

export const INSTALL_COMMAND = 'composer require patrickhanna/laravel-warrant'
