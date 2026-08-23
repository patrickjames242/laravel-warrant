> # ⚠️ FORWARD-LOOKING — READ THIS FIRST
>
> **The syntax-highlighting grammar in this folder (`warrant.tmLanguage.json`)
> deliberately highlights syntax that DOES NOT YET EXIST in the Warrant DSL
> parser.** The cross-schema reference features described below are a *design
> proposal*, not an implemented capability. The highlighter was updated ahead of
> the parser so the editor experience is ready when the features land.
>
> Concretely, these grammar constructs are FORWARD-LOOKING (grep the grammar for
> `FUTURE:`):
> - the `can(...)` and `check(...)` builtins used in expression position
> - the `for` and `with` keywords
> - `[ ]` ability lists and the `any` / `of` keywords
> - the `=` operator (context-mapping clause)
> - the `entity.name.namespace` scope on a schema handle after `for`
>
> **If these features are cut, renamed, or their syntax changes, update or
> remove the matching patterns in `warrant.tmLanguage.json` and
> `language-configuration.json`.** This document is the source of truth for what
> the highlighting is anticipating and why.
>
> Everything below is the full design/implementation plan for a future session
> that actually builds the parser/compiler support.

---

# Cross-Schema References in the Warrant Rule DSL — Design & Implementation Plan

> **Status:** design / not yet implemented. This document is the durable
> record of a design discussion. It is written for a future engineer or agent
> who will implement these features. Read the whole "Background" section before
> touching code — the feasibility of every feature below rests on specific
> facts about how the existing compiler works.
>
> **Project:** `laravel-warrant` (repo dir may be `laravel-warden`; PHP
> namespace is `Warrant\`). A Laravel authorization package whose rule DSL
> compiles to SQL `WHERE` clauses.

---

## 1. What we're building (the elevator pitch)

Today a Warrant rule is scoped to exactly **one** schema and can only reference
that schema's own abilities and conditions. We want a rule in schema **A** to be
able to reference **another** schema **B** — asking whether the user holds an
ability on B, or invoking one of B's conditions — so that authorization can be
composed across schemas.

**Final chosen surface syntax** — one uniform shape (see §3 for the full
rationale and the alternatives we rejected):

```
KEYWORD( <predicate> for <handle> [with <context map>] )
```

- **`KEYWORD`** is `can` (ability check) or `check` (condition check). The
  differing keyword labels the check kind at a glance while the structure stays
  identical.
- **`<predicate>`**: for `can`, an ability, `[a, b]` (all), or `any of [a, b]`
  (any). For `check`, a condition call `cond(args)`.
- **`<handle>`**: `some_other_schema` (unbound — no row) or
  `pay_periods(@context id)` (row-bound).
- **`with <context map>`** (optional): explicit, renamed context passed across
  the boundary — `k1 = @context outer_key, k2 = 'literal'`. No ambient
  inheritance (see §3.3).

Examples:

```text
# ability check, no specific row (capability / global)
if can(do_something for some_other_schema) they can create

# ability check on a SPECIFIC row of another schema
if can([create, view] for pay_periods(@context pay_period_id)) they can create
if can(any of [create, view] for pay_periods(@context pay_period_id)) they can create

# global condition on another schema (no row)
if check(is_open('some_param') for some_other_schema) they can view

# targeted condition on a SPECIFIC row of another schema
if check(is_payroll_published_for_user(@context user_id) for pay_periods(@context pay_period_id))
    they can create

# passing context explicitly across the boundary
if can(create for some_other_schema with as_of_date = @context pay_run_date, plan_id = @context plan_id)
    they can create

# composition is just boolean AND/OR of the above — each operand is one
# self-delimited KEYWORD(...) blob, so boundaries stay clear
if can(create_timesheet for users(@context user_id))
        and check(
            is_payroll_published_for_user(@context user_id)
                for pay_periods(@context pay_period_id)
                with user_id = @context user_id
        )
    they can create
```

---

## 2. Background: how the existing pipeline works

You cannot implement this safely without understanding these five facts. File
paths use the `src/` root; line numbers are approximate anchors, verify before
editing.

### 2.1 The compilation pipeline

`Lexer` → `WarrantParser` → AST (`*Node` classes) → `RuleSetValidator` →
`RuleSetCompiler` → an Illuminate query `Builder` predicate → attached to the
host query by `BuildsAccessQueries`.

- **Lexer** (`src/RuleSyntaxTree/Parsing/Lexer.php`): source string → flat
  `Token[]`. Single-char dispatch `match` at ~L63–77. Keyword table ~L20–28
  (`if/they/can/cannot/and/or/not`). `scanNamedBinding` ~L80–95 (the `:name`
  sigil). `scanContextRef` ~L97–121 (`@context`). `scanWord` ~L210–228.
  `isIdentifierStart`/`isIdentifierPart` ~L300–310 — **identifiers may contain
  `-` (dashes)**.
- **Parser** (`src/RuleSyntaxTree/Parsing/WarrantParser.php`): recursive
  descent. Grammar is in the docblock ~L18–30. `parsePrimary` ~L234–249,
  `parseCondition` ~L251–272, `parseArgument` ~L274–289, `parseContextRef`
  ~L297–306. A condition today is just `IDENTIFIER ( '(' args ')' )?`.
- **AST nodes** (`src/RuleSyntaxTree/`): `AndNode`, `OrNode`, `NotNode`,
  `ConditionNode`, `BooleanNode`, all implementing `IBooleanExpressionNode`.
  `ConditionNode` carries only `{string $conditionKey, array $parameters}`.
  `parameters` is a mixed list of resolved scalars and `ContextRef` objects.
- **`ContextRef`** (`src/RuleSyntaxTree/ContextRef.php`): a symbolic
  `{string $key}`. Unlike `:name`/`?` bindings (resolved to values at parse
  time), a `@context key` stays symbolic in the AST and is resolved **per check**
  at compile time from the check-time context bag.
- **Validator** (`src/RuleSyntaxTree/RuleSetValidator.php`): constructed with
  **one** `SchemaVocabulary`. `validate()` ~L24 checks every ability name is `*`
  or in `nonComputedAbilityNames()`; `validateConditionNames()` ~L52 walks the
  AST; `assertConditionExists()` ~L65 calls `$schema->conditionExists($key)`.
- **Compiler** (`src/RuleSyntaxTree/RuleSetCompiler.php`): see §2.2.

### 2.2 `compileAbility()` is a reusable predicate factory — THE key enabler

`RuleSetCompiler::compileAbility(user, query, ability, ruleSet, targetSqlId,
context)` (~L43) returns a nested `Builder` predicate for one ability:

```
( OR of every `can` rule's if-expression that lists the ability or * )
  AND ( AND of NOT(every conditional `cannot` rule's if-expression) )
```

with hard edges (deny-overrides): unconditional `cannot` → `1 = 0`; no `can`
rule → `1 = 0`; unconditional `can` → `1 = 1`.

- `applyExpression()` ~L160 walks the boolean tree; `NotNode` pushes negation to
  leaves (De Morgan) via `CompilationContext::negated()`.
- `applyCondition()` ~L197: resolves each `ContextRef` against
  `ctx->checkContext[key] ?? null` (~L214–222), then dispatches to the schema's
  `applyCondition()` (the `ConditionResolver` seam). A condition returning a
  bool short-circuits to `1 = 1`/`1 = 0`. Otherwise the leaf is either **inlined**
  as a correlated where-fragment (plain positive `can`) or wrapped as
  **`EXISTS` / `NOT EXISTS`** (~L257–269) when it is negated, comes from a
  `cannot`, or emits more than a plain where (a join/group/having).
- `CompilationContext` carries the immutable `negate` / `fromCannot` / `boolean`
  connector state.

**Why this matters:** the output is a detached, embeddable `Builder`. Schema B's
"can the user do X on row Y" is literally
`B->compileAbility(user, B_query, X, B_ruleSet, B.pk, context)` embedded as an
`EXISTS` leaf inside A's tree. The whole design is already composable — see how
`buildAvailableAbilitiesQuery` (`BuildsAccessQueries.php` ~L249) UNION-ALLs
per-ability predicates, and `userHasAbilities` (`WarrantSchema.php` ~L154–161)
already builds an `EXISTS` over `model->whereKey($id)` for a concrete target.

### 2.3 How conditions reach the query / user / target

`ConditionResolver` (`src/RuleSyntaxTree/ConditionResolver.php`) is the
compile-time seam; the schema implements it in
`src/Schema/Concerns/ResolvesConditions.php` → `applyConditionFilter()` ~L32. It
looks up the method by key ~L41, builds a `TargetedConditionContext` (needs a
non-null `targetSqlId`) or `GlobalConditionContext` ~L58–60, then invokes the
user-authored method ~L63. `conditionIsTargeted()` ~L73 tells targeted from
global. A targeted condition compiled with **no** target is forced false
(`RuleSetCompiler.php` ~L201).

The **target row in SQL is just a qualified column string** — `$targetSqlId`,
e.g. `$model->getQualifiedKeyName()` (`HasWarrantSchema.php` ~L185). Conditions
interpolate it raw and correlate against the outer query. There is no persistent
pivot table; the "join" between user and target is whatever SQL each condition
method emits.

### 2.4 @context is materialized to PDO bindings

`@context key` → `ContextRef` → resolved at compile time to a concrete PHP value
(`RuleSetCompiler.php` ~L214–222) → passed into the condition, which binds it as
a PDO parameter. There are no SQL placeholders for context. Required-context
enforcement happens earlier (`resolveEffectiveContext` /
`assertAbilitiesHaveRequiredContext` in `BuildsAccessQueries.php`; `#[RequiredContext]`
constants and per-ability `requiredContext`).

### 2.5 Schemas are globally reachable by key

`WarrantManager` (`src/WarrantManager.php`) is the singleton registry built from
`warrant.schemas` config. `getSchemaForKey('some_other_schema')` ~L91 returns
B's class-string; schemas are cheap stateless `new static` instances.
`registeredSchemas()` ~L210 enumerates all. **"Capability schemas" with no model
already exist** (`const model = ''`, note ~L48) — these are the natural target of
the no-row `can(do_something for some_other_schema)` form.

**But** compilation is currently single-schema: `RuleSetCompiler` holds exactly
one `ConditionResolver` (schema A) and never consults `WarrantManager`. Threading
the manager (and the acting user, for B's rule resolution) into the compile path
is the main structural change any `can(...)` feature requires.

---

## 3. Design decisions and WHY (do not relitigate these without reason)

### 3.1 The chosen shape: `KEYWORD( <predicate> for <handle> [with <map>] )`

This shape was reached after rejecting several alternatives. Recording the
journey so a future agent doesn't re-walk it:

- **`schema.can(...)` / `schema.member(...)` (dot receiver form) — rejected.**
  Reads target-first (`users.can(create_timesheet)` ≈ "users can create") which
  is backwards — you want to read the verb/object first, then the target. It also
  blurs subject vs. target: the receiver *looks* like the subject, but in Warrant
  the subject is always the current user and the handle is the target. (`->` was
  rejected even earlier: it looks like PHP member access — which turned out not to
  matter to us — but also collides mechanically with dash-in-identifiers, since
  `-` is a legal identifier char, so `some_schema->can` lexes the `-` into the
  identifier. That mechanical objection stands regardless of aesthetics, so even
  with the PHP concern dropped, `->` is not worth the lookahead.)
- **Postfix `predicate for handle with map` (target trails, unenclosed) —
  rejected.** Reads naturally, but destroys visual boundaries: with the target
  and `with` dangling after the predicate (often onto extra lines), you cannot
  see where one boolean operand ends and the next `and`/`or` operand begins.
- **`KEYWORD( predicate for handle [with map] )` — chosen.** It keeps the natural
  predicate-first reading (the `for <handle>` trails, as English wants) *and*
  encloses the whole thing in one bracket, so every boolean operand is a single
  self-delimited `KEYWORD(...)` blob that ends at its matching `)`. `and`/`or`
  then sit cleanly between closed blocks. Best of both.

Grammar notes:
- `can` is an existing reserved keyword; `check`, `for`, `with` are new reserved
  keywords. `any`/`of` are contextual list markers.
- `can`/`check` are distinguished from the clause keyword `can` (`they can …`) by
  being in expression position and followed by `(`. No conflict: `if can(...)`
  vs `they can create`.

### 3.2 The two handle forms

- **Unbound** — `some_other_schema` — no specific row. Valid inside `can(... for
  some_other_schema)` (no-row ability check) and `check(<global cond> for
  some_other_schema)`.
- **Row-bound** — `pay_periods(@context id)` — a specific row (`B.pk = ?`). Valid
  inside `can(... for pay_periods(@context id))` (ability check on that row) and
  `check(<targeted cond> for pay_periods(@context id))`.

Validation rules that fall out: a **targeted** condition requires a **row-bound**
handle (error otherwise — "needs a row"); a **global** condition on a row-bound
handle is at least a warning. Use the existing `conditionIsTargeted()` to enforce.

### 3.3 Context across the boundary: EXPLICIT ONLY, via `with`. No ambient inheritance.

We considered letting B inherit A's `@context` bag automatically. **Rejected**,
for two reasons the package author raised and that are correct:

1. **Semantic collision.** `user_id` in A's context may mean something different
   from `user_id` in B's. Ambient inheritance silently passes the wrong value.
   Renaming at the boundary (`with b_key = @context a_value`) makes this
   impossible by construction.
2. **Opacity of the required-context tree.** With ambient inheritance you cannot
   tell, by reading a rule, what context the whole recursive tree needs, and you
   cannot *compute* it either — it's an open union of every descendant's raw key
   names.

The insight: **explicit boundary mapping is what makes required-context a closed,
computable set.** Because every hop renames outer→inner and B is allowed to see
*only* what you explicitly map, you can walk the reference graph and flatten
"to evaluate `A:create`, the caller must supply exactly {these keys}" — and
enforce it at compile time. This turns the author's worry into a tractable,
enforceable property (see Feature 7).

**Spelling:** a `with` clause inside the `can(...)`/`check(...)`, e.g.
`can(create for some_other_schema with as_of_date = @context pay_run_date, plan_id = @context plan_id)`.
Direction is `<B's key> = <outer expression>`. Do NOT use a PHP array form
`['x' => @context y]` — `=` inside `with` is universal, not PHP-flavored.
Keeping `with` inside the enclosing bracket (rather than dangling after it) is
what preserves the operand boundary (§3.1).

**Consistency payoff:** the invariant is *everything B sees is passed explicitly
at the call site — nothing ambient.* For `check(...)`, the condition's own
arguments already carry its inputs; for `can(...)`, the `with` map carries what
B's rules reference. State and enforce that invariant.

---

## 4. Cross-cutting concerns (apply to multiple features)

- **Cycle detection (MANDATORY once `can(...)` exists).** `can(...)` recursively
  compiles B's rules; if `A:create` → `B:do` → `A:...` you get infinite
  compile-time recursion and unbounded nested SQL. Add a visited-set of
  `(schemaKey, ability)` and/or a max-depth cap in the compile path. NOTE:
  `check(...)` (Features 1–2) does NOT recurse into rules — a condition is a PHP
  method emitting SQL — so it carries no cycle risk. Cycles enter only with rule
  recursion (Feature 3+).
- **Per-check rule resolution of B.** `can(...)` needs B's *resolved rule set for
  the current user* — from the `RuleResolver` (`ResolvesRuleSets::resolveRuleSet`
  ~L22), not from B's static vocabulary. Each distinct referenced schema triggers
  another rule-store lookup + validate + compile. Memoize per `(schema, user)`
  within a single check.
- **Single-schema guards to avoid tripping.** `WarrantSchema::resolveCheckTarget()`
  (~L239–256) throws on foreign models; `HasWarrantSchema::newWarrantSchemaInstance()`
  (~L261) asserts `schema.model === model.class`. The cross-schema path must call
  `compileAbility()` / `applyCondition()` directly and NOT go through the
  check-target API that enforces these.
- **Reachability is safe as-is.** `ReachabilityAnalyzer` (`src/RuleSyntaxTree/
  ReachabilityAnalyzer.php`) is purely structural — it treats any conditional rule
  as opaque `MAYBE` and never recurses across schemas. A rule containing a
  cross-schema leaf is simply "conditional" → MAYBE. No change needed, no cycle
  risk — UNLESS someone later makes reachability transitive across schemas, in
  which case it needs its own visited-set guard.
- **Round-trip writer (correctness, not cosmetics).** Rules are stored as data
  and re-serialized. `RuleSyntaxWriter` (`src/RuleSyntaxTree/RuleSyntaxWriter.php`
  — `writeCondition` ~L122, `arg` ~L131, `ContextRef` render ~L136–138) MUST learn
  every new syntax form, or bound-syntax round-trips break.
- **Denial diagnostics.** `DiagnosesDenials` / `RuleSetCompiler::matchesCondition`
  (~L119) re-compile conditions to build denial messages. New node types should be
  handled there or explicitly degrade.
- **Editor tooling.** The VS Code TextMate grammar (this folder) and PhpStorm
  support (under `editors/`) need the new keywords/operators for highlighting.
  **(The VS Code grammar is already done ahead of the parser — see the banner at
  the top of this file.)**
- **Performance — the good news.** Every example targets either no row or an
  `@context` **constant**, never an A column. So each cross-schema leaf is a
  *row-independent* subquery the planner can evaluate once per check, not per row
  — it behaves like a global gate. The risk is **nesting depth**: each `can(...)`
  embeds B's entire compiled predicate (which may contain its own EXISTS leaves
  and further cross-schema refs). Cap depth. MySQL/MariaDB optimize nested/
  dependent subqueries worse than Postgres — add per-driver SQL-surface tests
  (mirror the existing `tests/*SqlTest.php` style, which normalizes emitted SQL
  against expected strings).

---

## 5. The roadmap — independently shippable features, easiest → hardest

Ordering follows a single distinction: **`check(...)`** features just call a
method on another schema (light; no recursion, no cycles); **`can(...)`** features
recursively resolve and compile another schema's rules (heavy; need the
manager+user wiring and the cycle guard). Difficulty and *value* diverge — see the
note at the end.

### Feature 1 — `check(<global condition> for <schema>)`
`if check(is_open('p') for some_other_schema) they can view`

**Why easiest / ships first:** smallest end-to-end slice, so it pays the one-time
grammar cost for `check(...)`, `for`, and the unbound handle. Pure dispatch — no
rule resolution of B, no recursion, no cycles.

**Introduces:** the `check(...)` builtin; `for`; the **unbound handle**; a new AST
node (e.g. `CrossSchemaConditionNode {schemaKey, conditionKey, parameters,
boundRowExpr?}`); foreign-schema resolution via `WarrantManager` in the validator.

**Touch:** `Lexer` (new keyword tokens), `WarrantParser` (`parsePrimary`/new
production for `check(...)`), new AST node, `RuleSetValidator` (resolve B by key,
assert the condition exists AND is global via B's vocabulary),
`RuleSetCompiler::applyExpression` (dispatch to B's
`ConditionResolver::applyCondition` instead of A's), `RuleSyntaxWriter`.

**Reuses:** existing condition-emission path verbatim.

**Gotcha:** enforce global-only for the unbound handle (a targeted condition here
has no row → error).

### Feature 2 — `check(<targeted condition> for <schema>(@context id))`
`if check(is_payroll_published_for_user(@context user_id) for pay_periods(@context id)) they can create`

**Why next:** adds the **row-bound handle** grammar `schema(@context id)` and a
bound `targetSqlId` (`B.pk = ?`). Still pure dispatch — B's targeted-condition
method runs against that row, wrapped as EXISTS. No rule recursion. Genuinely
useful alone (this was one of the author's real examples).

**Introduces:** bound-handle grammar; the validation rule targeted⇒bound,
global⇒unbound.

**Touch:** parser (handle with an arg), the AST node's `boundRowExpr`, validator
(targeted-vs-global check), compiler (build `targetSqlId` constrained to
`B.pk = @context id`, dispatch B's targeted condition), writer.

**Reuses:** Feature 1's foreign-schema resolution; existing targeted-condition
EXISTS emission.

### Feature 3 — `can(<single ability> for <schema>)` (no-row)
`if can(do_something for some_other_schema) they can create`

**Why the difficulty jump:** first feature that recursively compiles B's *rules*.
This is where the heavy wiring lands.

**Introduces / includes (as required internal deliverables, not separate
features):**
- The `can(...)` builtin in expression position (parser must accept the reserved
  `can` token followed by `(`).
- Thread `WarrantManager` + the acting user into `RuleSetCompiler`.
- Resolve B's rule set for the user, validate, `B->compileAbility(user, B_query,
  ability, B_ruleSet, targetSqlId: null, context)`, embed as an EXISTS leaf.
- **Cycle detection** (visited-set / depth cap).
- Per-`(schema,user)` rule-set memoization within a check.

**Context caveat for this phase:** B is compiled with **no context** — its
`@context` refs resolve to `null` (the existing tolerant behavior at
`RuleSetCompiler.php` ~L216). This is fully sufficient for context-free
capability/role checks, which is the primary use case. Context-*dependent*
abilities wait for Feature 6. Consider making it a compile error (rather than a
silent null) if B's referenced ability requires context this phase can't supply —
that error is the seed of Feature 7.

**Touch:** parser (`can(IDENTIFIER for handle)`), new AST node (e.g.
`CrossSchemaCanNode {schemaKey, abilities[], matchMode, boundRowExpr?, contextMap}`),
validator (assert ability exists in B's `nonComputedAbilityNames()`), compiler
(the recursion), writer.

### Feature 4 — Ability lists + match mode for `can`
`can([create, view] for …)` (ALL) and `can(any of [create, view] for …)` (ANY)

**Why:** a modifier on `can`'s predicate. New `[` `]` tokens and `any`/`of`
keywords, mapping onto the existing `AbilityMatchMode` (AND vs OR of per-ability
predicates). Small grammar addition, no new semantics.

**Depends on:** Feature 3. **Touch:** lexer (brackets), parser (list + `any of`
prefix), the AST node's `abilities[]`/`matchMode`, compiler (AND/OR the
per-ability predicates), writer.

### Feature 5 — Row-bound `can`
`if can([create, view] for pay_periods(@context id)) they can create`

**Why:** mostly composition — the bound handle from Feature 2 + the `can`
machinery from Feature 3, with `targetSqlId` constrained to `B.pk = @context id`.
Little genuinely new code.

**Depends on:** Features 2 + 3 (and 4 for the list form).

### Feature 6 — Explicit `with` context passing
`can(create for some_other_schema with as_of_date = @context pay_run_date, plan_id = @context plan_id)`

**Why:** new grammar clause (inside the `can(...)`) + threading a **scoped,
renamed** context bag into B's compile (NO ambient channel). This is what makes
context-*dependent* cross-schema abilities work and kills the
`user_id`-collision problem. Moderate: changes how context is resolved across the
boundary — instead of B seeing A's bag, B sees a fresh bag built solely from the
`with` mapping.

**Depends on:** Feature 3 (pairs with Feature 5). **Touch:** lexer (`with`
keyword, `=`), parser (the clause), AST node's `contextMap`, compiler (build B's
context from the map, resolving each RHS `@context`/literal against A's context).

### Feature 7 — Required-context analyzer + compile-time enforcement
`requiredContextFor(ability)` + "your `with` clause is missing key X" errors

**Why hardest / capstone:** a cycle-guarded graph walk over the cross-schema
reference tree (with per-user rule resolution) that flattens the transitive
required-context set, PLUS a compiler check that every hop's `with` clause covers
exactly what its target needs (missing → error naming the key; extra → warning).
Turns Feature 3's caveat and Feature 6's mapping from "hope it's right" into
"guaranteed before you ship," and gives the author tooling to SEE the tree they
were worried about.

**Depends on:** Feature 6. **Touch:** a new analyzer (walk the reference graph,
reuse the cycle guard, resolve each schema's rules per user), a compile-time
completeness check, and a public reporting API.

---

## 6. Sequencing notes

- **Difficulty vs value diverge.** Feature 3 (no-row `can`) is probably the
  highest-value feature but sits mid-difficulty. If optimizing for impact, do
  1 → 3 first and treat Feature 2 as a parallel track.
- **Package Features 6 + 7 as one "context-dependent cross-schema" milestone.**
  Do not lean on context-passing `can(...)` in production until Feature 7's
  enforcement exists; they should land close together even though each technically
  ships alone.
- **Grammar cost is front-loaded.** Feature 1 pays for `check(...)` + `for` +
  unbound handle; Feature 2 adds the bound handle; Feature 3 adds the `can(...)`
  builtin; Feature 4 adds brackets + `any of`; Feature 6 adds `with`. After that
  the grammar is essentially complete. **(The VS Code highlighter in this folder
  already covers all of it — see the top banner.)**

---

## 7. Suggested new AST node shapes (starting point, refine during impl)

```
// implements IBooleanExpressionNode
CrossSchemaCanNode {           // from can(<predicate> for <handle> [with <map>])
    string   $schemaKey,          // "pay_periods"
    string[] $abilities,          // ["create","view"]
    MatchMode $matchMode,         // ALL (default) | ANY (from "any of")
    ?Expr    $boundRowExpr,       // the @context id in for schema(@context id); null = unbound/no-row
    array    $contextMap,         // [ "as_of_date" => ContextRef("pay_run_date"), ... ]; Feature 6
}

CrossSchemaConditionNode {     // from check(<cond call> for <handle> [with <map>])
    string   $schemaKey,
    string   $conditionKey,       // "is_payroll_published_for_user" | "is_open"
    array    $parameters,         // same shape as ConditionNode::$parameters
    ?Expr    $boundRowExpr,       // present => targeted (Feature 2); absent => global (Feature 1)
    array    $contextMap,         // Feature 6 (if `with` is allowed on check(...) too)
}
```

Every place that switches on `IBooleanExpressionNode` must gain arms for these:
`RuleSetCompiler::applyExpression`, `RuleSetValidator::validateConditionNames`,
`RuleSyntaxWriter`, and (if made cross-schema-aware later) reachability and
denial diagnostics.

---

## 8. Test strategy

Mirror the existing SQL-surface tests (`tests/FilterQuerySqlTest.php`,
`tests/NoTargetAbilitiesSqlTest.php`, `tests/SelectUserAbilitiesSqlTest.php`,
normalized via `tests/SqlNormalizerTest.php` helpers). For each feature:

1. Parser round-trip tests (`RuleSyntaxParserTest` / `RuleSyntaxWriterTest`
   style) — the new syntax parses and re-serializes identically.
2. Validator tests — unknown foreign schema/ability/condition throws; global-vs-
   targeted misuse throws; (Feature 7) missing `with` key throws.
3. SQL-surface tests — the emitted, normalized SQL matches an expected string, on
   each supported driver (SQLite/MySQL/Postgres) since subquery shapes differ.
4. Cycle tests (Feature 3+) — an A↔B cycle is detected and errors, not hangs.
5. Reachability tests — a rule with a cross-schema leaf reports MAYBE.

---

## 9. Editor-tooling status (this folder)

The VS Code grammar (`warrant.tmLanguage.json`) and `language-configuration.json`
in this folder were updated to highlight ALL of the syntax above **before** the
parser supports it. Forward-looking patterns are tagged with `FUTURE:` in their
`comment` fields. Scope choices:

- `can` / `check` builtins, `for` / `with` / `any` / `of` → `keyword.control.warrant`
- schema handle name (the identifier after `for`) → `entity.name.namespace.warrant`
- `=` (in `with`) → `keyword.operator.warrant`
- `[` `]` → `punctuation.separator.warrant`

Known highlighter limitation: the `for <handle>` rule is a single-line match, so
if `for` and the schema name land on different lines the name won't get the
`namespace` scope (it falls back to the generic identifier scope). Harmless.

When the parser lands (or if the syntax changes), reconcile these patterns with
the final grammar and bump the extension version in `package.json` / `CHANGELOG.md`.
