export const HERO_RULE = `
if owns_document or manages_team
they can view, update

if document_locked and not is_admin
they cannot update
because 'This document is locked.'`

/** Every line of the rule that takes part in answering an `update` question. */
export const HERO_RULE_HIGHLIGHT = [0, 1, 3, 4, 5]

export interface Document {
  id: number
  title: string
  owner: string
  team: string
  locked: boolean
  abilities: readonly string[]
}

export const DOCUMENTS: readonly Document[] = [
  { id: 1, title: 'Q3 roadmap', owner: 'Patrick', team: 'Product', locked: false, abilities: ['view', 'update'] },
  { id: 2, title: 'Brand guidelines', owner: 'Ana', team: 'Design', locked: false, abilities: ['view', 'update'] },
  { id: 3, title: 'Pricing memo', owner: 'Ana', team: 'Sales', locked: false, abilities: [] },
  { id: 4, title: 'Board deck', owner: 'Patrick', team: 'Product', locked: true, abilities: ['view'] },
  { id: 5, title: 'Hiring plan', owner: 'Leo', team: 'Design', locked: true, abilities: ['view'] },
]

export type HeroQuestion = 'filter' | 'check' | 'abilities'

export interface HeroMode {
  label: string
  question: string
  /** The heading of the result column in the rows view. */
  resultHeading: string
  code: string
  sql: string
  sqlHighlight: readonly number[]
  rowsNote: string
}

export const HERO_QUESTIONS: readonly HeroQuestion[] = ['filter', 'check', 'abilities']

export const HERO_MODES: Record<HeroQuestion, HeroMode> = {
  filter: {
    label: 'FILTER',
    question: 'Which can they update?',
    resultHeading: 'userHasAbility',
    code: `Document::query()
    ->userHasAbility('update')
    ->get();                // #1, #2`,
    sqlHighlight: [2, 3, 4, 6],
    rowsNote: '→ 2 rows',
    sql: `select * from documents
where (
    documents.owner_id = 7                              -- owns_document
    or documents.team_id in (select team_id             -- manages_team
        from managed_teams where user_id = 7)
)
and not (documents.is_locked and not false)             -- document_locked`,
  },
  check: {
    label: 'CHECK',
    question: 'Can they update this one?',
    resultHeading: 'result',
    code: `$document = Document::find(2);
Warrant::can('update', $document);      // true

$locked = Document::find(4);
Warrant::authorize('update', $locked);
// throws 403: "This document is locked."`,
    sqlHighlight: [],
    rowsNote: '→ true',
    sql: `select exists (
    select 1 from documents
    where documents.id = 2
    and (
        documents.owner_id = 7                          -- owns_document
        or documents.team_id in (select team_id         -- manages_team
            from managed_teams where user_id = 7)
    )
    and not (documents.is_locked and not false)         -- document_locked
)`,
  },
  abilities: {
    label: 'ABILITIES',
    question: 'What can they do per row?',
    resultHeading: 'abilities ← added',
    code: `Document::query()
    ->selectUserAbilities()
    ->get();                // + abilities column`,
    sqlHighlight: [1, 2, 3, 4, 5, 6, 7],
    rowsNote: '→ abilities per row',
    sql: `select documents.*,
    json_array(
        case when (documents.owner_id = 7 or documents.team_id in (…))
            then 'view' end,
        case when (documents.owner_id = 7 or documents.team_id in (…))
            and not (documents.is_locked and not false)
            then 'update' end
    ) as abilities
from documents`,
  },
}
