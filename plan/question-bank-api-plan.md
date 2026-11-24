# Moodle question-bank API plugin plan

## Goal and constraints

Provide a site-local Moodle web-service plugin for safely inspecting and
managing question-bank content. Target the Moodle 5.3.1 checkout in the
workspace. The implementation must use Moodle question-bank APIs and format
import/export code; it must not write question, version, category, usage, or
file records directly through SQL.

The initial deployment should be administrator-only, use a dedicated Moodle
web-service service and token, require HTTPS, and enforce Moodle context and
question-bank capabilities for every operation. Question text, answers, and
feedback are sensitive and must not be copied into operational logs.

## Repository findings

The Moodle checkout registers some question-related external functions, but
they do not provide a complete question-bank management API:

- `core_question_move_questions` moves questions, and
  `core_question_search_shared_banks` searches shared banks.
- `core_question_update_flag` and
  `core_question_get_random_question_summaries` concern question attempts or
  random-question summaries, not question-bank CRUD.
- Question-bank plugins register functions for setting question-version
  status, moving categories, and updating tags. These are primarily narrow
  UI/AJAX operations, not a complete application API.
- `core_files_upload` stages an uploaded file. It does not import XML.
  `core_files_delete_draft_files` deletes draft files, not imported questions.
- XML import and export are implemented through the Question bank pages and
  Moodle's question-format import/export code. Question deletion is handled
  by the Question bank delete functionality and has to account for versions
  and questions in use.

Relevant Moodle source areas are `public/lib/db/services.php`,
`public/question/bank/importquestions`, `public/question/bank/exporttoxml`,
`public/question/bank/deletequestion`, and the `db/services.php` files in
`editquestion`, `managecategories`, and `tagquestion`.

## Proposed plugin

Create `moodle-plugins/local/bosonanceqbank/` in this repository, with the
Moodle component name `local_bosonanceqbank`. Follow the existing
`local_bosonanceblocks` plugin's packaging, external-function registration,
administrator-only initial access, test layout, installation documentation,
and staging-first deployment guidance.

### Phase 1: read-only operations and XML inspection

Expose narrowly scoped, paginated operations:

- List categories within an authorized context.
- List/search questions by category, type, status, tag, and text criteria.
- Get a question and its versions, returning only requested fields.
- Report question usage and relevant version/history metadata before writes.
- Export selected questions or a category through Moodle's XML export code.
- Validate an XML upload and return a dry-run report without changing Moodle.

Use explicit context and category IDs; do not infer them from filenames or
XML. Limit page size and response fields. Do not return hidden or inaccessible
questions merely because a caller can name their IDs.

### Phase 2: controlled XML import

- Accept a Moodle draft-file item ID owned by the authenticated user, or use
  an equivalently scoped upload flow.
- Apply file-size, question-count, format, and supported-question-type limits.
- Require the destination category and context explicitly.
- Default to additive import only. Do not update or delete existing questions
  based on matching names, missing XML entries, or XML-supplied context.
- Have the preview compare the file and destination against prior successful
  imports from this plugin and identify possible matches in the target bank.
  Distinguish exact import-signature matches from weaker name/type matches;
  names alone must not trigger automatic updates or deletion.
- Show a prominent duplicate warning with counts and safe identifiers before
  apply. Block an exact repeat of a successful import with the same destination
  by default; require an explicit duplicate override for an intentional repeat.
- Require the caller to acknowledge the reviewed warning set before apply. Bind
  that acknowledgment to the preview, file hash, destination, and caller, and
  reject it if any of them or the target state changes.
- Require a successful validation preview before an explicit apply operation.
- Return per-question outcomes and Moodle-generated IDs; report errors rather
  than silently skipping invalid items.
- Keep staged-file cleanup separate from question import and question
  deletion.

### Phase 3: reversible question-bank updates

Add separate operations for:

- Move questions between authorized categories.
- Set a question version's supported status.
- Update tags and manage categories.
- Create questions and update questions by creating a new version.

For updates, require the caller's expected current version and reject stale
requests. Validate source and destination contexts and required capabilities
for every item in a batch. Use Moodle's question editing and category APIs;
do not use generic direct-database updates. Restrict create/update payloads to
question types and fields explicitly supported by the plugin.

#### Move implementation safeguards

Moodle's bulk-move helper moves a bank entry, so selecting one version moves
all versions of that entry. It also selects child questions, can rename an
entry's ID number on a destination collision, and invokes tag and file
handling. A safe implementation must preview and verify all affected entries
and versions, not just the submitted question IDs. Initially reject cases
whose effects cannot be fully previewed, including ID-number collisions and
implicit child moves; limit moves to the same bank context until cross-context
file and tag behavior is separately tested.

Require current-version and source-category checks, per-question move
capabilities, destination add capability, explicit confirmation, a bounded
transaction and per-entry results. A plugin lock alone does not prevent
concurrent changes through Moodle's UI or other services; establish and test
how those races are handled before claiming stale-write protection. Verify
success, rollback, permissions, stale versions, child questions, ID-number
collisions, usage and concurrent edits in an isolated Moodle test environment
before enabling the write endpoint.

### Phase 4: deletion, only after separate review

Initially provide delete-preview and reversible hide/restore operations only.
Do not expose permanent deletion in the first release. If permanent deletion
is later approved:

- Show affected question entries and versions, category, and usage information
  in a fresh preview.
- Require explicit question/version IDs and a separate confirmation for
  deleting all versions.
- Recheck usage and permissions immediately before applying.
- Block or clearly report questions still in use; never make deletion an
  implicit side effect of import, category deletion, or bulk update.

## Write-operation safety requirements

- Separate read and write external functions. Put only required functions in
  a dedicated web service; use a dedicated account/token and HTTPS.
- Check the user's capability in the actual question/category context on every
  request. Use the narrow Moodle capabilities required for viewing, adding,
  editing, or managing categories; do not rely only on a global administrator
  check.
- Make preview/dry-run the default for imports and batch writes. The preview
  should identify exact target IDs, versions, counts, and proposed actions.
  Applying must refer to the reviewed preview and fail if its inputs or target
  state have changed.
- Compute an import signature from the uploaded file and resolved destination;
  retain successful signatures to detect accidental re-imports through this
  plugin. Report possible existing-question matches separately from exact
  repeats, and never describe heuristic matching as definitive deduplication.
  Require explicit acknowledgment of duplicate warnings before importing.
- Use bounded batches, Moodle parameter validation, strict XML validation,
  and a fixed supported-format/type allowlist. Do not accept filesystem paths
  or caller-provided context overrides.
- Make retries safe where possible with an idempotency key or request hash.
  Use Moodle transactions where supported, re-read versions/usages before
  writes, and report conflicts rather than overwriting concurrent edits.
- Emit Moodle events or equivalent audit records containing actor, operation,
  target IDs, request/preview identifier, counts, and outcome. Never log
  tokens or full question content.
- Treat preview hashes as a stale-state check, not as a substitute for
  locking or a database backup.

## Validation and deployment

Add Moodle PHPUnit external-function tests in the plugin for:

- Read and write capability checks, including cross-context and inaccessible
  IDs.
- Pagination and response-field limits.
- Malformed, oversized, unsupported, or invalid XML.
- Preview operations making no database changes and reporting potential
  duplicates.
- Additive import, category targeting, exact-repeat blocking, explicit
  duplicate acknowledgment, stale-preview rejection, and clear failures.
- Stale-version conflicts and concurrent changes.
- Safe handling of used questions during delete preview and hide/delete flows.

Install on a Moodle 5.3.1 staging copy, run the focused PHPUnit tests, inspect
the functions in Moodle's Web Services API Documentation, and test with a
restricted token. Before production deployment, take and verify an independent
database and Moodle data backup, document rollback, and verify that no
question-bank changes occur before an explicit apply request.

## Initial release boundary

Start with read-only listing/details/usage, XML validation, and export. Add
additive XML import only after preview, permission, and rollback tests pass.
Defer generic create/update and permanent deletion until their versioning,
usage, concurrency, and audit behavior is covered by tests and a separate
review.

### Implementation status

The plugin is in
[`moodle-plugins/local/bosonanceqbank`](../moodle-plugins/local/bosonanceqbank/README.md).

**Phase 1 (0.1.0, done):** paginated categories/questions, details/version
metadata and Moodle usage flags, explicit-ID XML export, and XML preview with
category subtree checks and possible-duplicate warnings. Live check:
`scripts/test-moodle-qbank-api.py` passed against the site-level system bank,
including hidden-question export rejection; previews and rejected calls left
the bank unchanged.

**Phase 2 (0.2.0, implemented, awaiting staging verification):**

- `preview_xml` now also runs Moodle's own XML reader and grade matching
  without saving, then records a single-use, 30-minute preview bound to the
  caller, file hash, bank, category, warning set and destination-subtree
  state. It returns `previewtoken`, `warningshash` and
  `requiresduplicateoverride`.
- New warnings: `exact_repeat` (same bytes already imported into the same
  bank and category; blocks unless `allowduplicate=1`) and
  `file_imported_elsewhere`.
- New `import_xml(previewtoken, warningshash, allowduplicate)`: rejects
  unknown, foreign, used or expired tokens, a mismatched warnings hash, and
  any change to the file or destination since preview (`previewstale`).
  Imports into one bank are serialized with a lock.
- The import is additive and all-or-nothing: Moodle's importer runs in one
  transaction, existing categories are never edited, and the created count,
  types and destinations are verified before commit. Any failure rolls back
  everything and leaves the token unused, so a retry is safe; a successful
  token cannot be replayed.
- Each success stores an audit record (caller, file hash, destination, question
  IDs, duplicate override) and triggers the core *Questions imported* event.
  The privacy provider exports these and anonymises them on deletion.
- Staged draft files are never deleted by the plugin.

Staging verification: Moodle 5.3.1 PHPUnit passed 35 tests (96 assertions).
The live import script passed 37 checks with 6 skips in an empty bank, then
36 checks with 3 skips against the new test category. Hidden-question and
cross-bank checks remain skipped on staging.

**Phase 3 (0.3.0, initial move staging verification):**
single-entry, same-bank moves have a default-off feature switch, a
single-use preview, version and category checks, an exclusive-access
confirmation, transaction and audit record. Parent/child questions, used
questions, banks with question-set references, out-of-context question tags,
and ID-number collisions are rejected. Staging PHPUnit passed 44 tests with
128 assertions on Moodle 5.3.1 with MariaDB. A live test moved one disposable
entry and back, and verified replay and stale-preview rejection; concurrent
writes and other live edge cases were not tested. The remaining Phase 3
operations and Phase 4 remain deferred.

**Phase 3 (0.4.0, initial status staging verification):** A
default-disabled preview/confirm operation changes the latest version between
ready, draft and hidden. It blocks usage, question-set references and
parent/child questions and requires exclusive bank access. Hidden uses a
targeted version-status update because Moodle's status service excludes it
and its delete helper may permanently delete an unused question. One disposable
question passed a live status round trip and replay/stale rejection; existing
attempts blocked other questions. Staging PHPUnit passed 51 tests with 168
assertions on MariaDB; concurrency remains untested.

**Phase 3 (0.5.0, experimental, unverified):** A default-disabled tag
replacement operation previews a latest-version assignment and accepts only
existing standard tags. It requires exclusive access to both the bank and
relevant global tag management. Moodle PHPUnit, live staging, concurrency and
independent safety review remain outstanding; do not enable on production.
Category management, question creation and versioned updates, and deletion
preview remain unimplemented. Permanent deletion remains deferred pending
separate review.
