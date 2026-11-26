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

### Phase 4: guarded deletion, experimental source implemented

The first stable release, 1.0.0, contains no permanent deletion endpoints.
Source 1.1.0 adds default-disabled deletion of unused whole question entries
and empty leaf categories; 1.2.0 adds explicit empty-subtree deletion; 1.3.0
adds a distinct opt-in to delete all unused entries/versions in a category
subtree before deleting its categories.
These remain alpha functionality pending database-backed staging validation
and separate production approval:

- Show affected question entries and versions, category, and usage information
  in a fresh preview.
- Require the latest question ID and expected version, and separate irreversible
  confirmation for deleting the whole entry and every version.
- Reject attempts, entry references, bank-wide question-set references, native
  usage reports, parent/child questions and unsupported types. Recheck usage,
  permissions and the previewed state immediately before applying.
- Keep category deletion leaf-only by default. Optional `recursive=true`
  selects the root and every descendant only when all have zero question-bank
  entries, including hidden/draft entries and entries without visible versions.
  Preserve Moodle's top-category and sole-child-of-top protections.
- Keep empty-only `recursive=true` behavior strict. A separate
  `deleteunusedquestions=true` preview option implies subtree mode and explicitly
  includes every question-bank entry and all its versions. Run the existing
  whole-entry usage, attempts, references, type, question-parent, permissions,
  file, option and tag-assignment guards for every entry; fail the whole
  operation if any candidate is used or unsupported. Never skip entries.
- Show all planned question entry IDs, latest versions, every version's ID,
  status and timestamp, question name/type, and containing category in preview.
  Keep names/content out of the stored audit and successful apply scope.
- In one transaction, delete all candidate entries with Moodle's question
  deletion helper, then delete categories bottom-up. Verify every entry and
  category is gone; retain global tag definitions. Roll back the complete
  operation if any native cleanup/postcondition fails.
- Preview the complete recursive category list, names, IDs, parents and count.
  Bind the scope to one caller-bound, expiring, single-use token; apply must not
  accept a new scope override.
- Lock the previewed rows, reanalyse the tree, delete children before parents
  through Moodle's category manager, and verify outside parents/siblings survive.
  Use one transaction and successful audit for the whole subtree. Any failure
  rolls back all deletions and leaves the token unused.
- Limit deletion to 100 versions per entry, 100 entries and 1000 total versions
  per category cascade, 1000 total subtree categories (including the root),
  1000 direct category siblings and 10000 bank-context file records.
- Require exclusive access from before preview through apply. Never delete
  questions implicitly through import or empty-only category deletion, delete
  used questions, relocate children, automatically retry an uncertain result,
  or offer an undo without a backup. Moodle/third-party usage outside the
  available reference and usage checks cannot be ruled out.

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
- Preview operations making no question/category/tag changes, while permitting
  scoped preview/audit storage, and reporting potential duplicates.
- Additive import, category targeting, exact-repeat blocking, explicit
  duplicate acknowledgment, stale-preview rejection, and clear failures.
- Stale-version conflicts and concurrent changes.
- Safe handling of used questions during delete preview and hide/delete flows.
- Recursive deletion opt-in, empty-subtree success, occupied descendants,
  added/moved/renamed descendants, replay and competing previews, bottom-up
  ordering independent of numeric IDs, rollback after a later failure, exact
  1000/1001-category limits, and name-free deletion audit scope.
- Unused-question subtree cascade: all questions/versions/categories appear
  in preview; unused hidden/draft entries can be removed; attempts, references,
  question-set references, unsupported/child questions and stale content abort
  the whole operation; rollback preserves every question/category and leaves
  the token unused; audit/apply omit names and retain tag definitions.

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

**Current source: 1.3.0 alpha (`2026112507`), as of 2026-11-25.**
The immutable 1.0.0 read/export production-baseline package remains unchanged.
The chronological entries below describe their respective versions; earlier
statements that deletion or missing-tag creation is deferred are historical,
not the current source scope.

| Operation | Current implemented boundary |
| --- | --- |
| Categories/questions, details/usage, XML export | Explicit authorized bank/IDs, bounded responses |
| XML preview/import | Additive, scoped category paths, duplicate acknowledgement; missing question tags created only during confirmed import |
| Question moves | One whole entry/all versions within the same bank |
| Status | Latest-version ready/draft/hidden changes |
| Tag replacement/clearing | Existing standard tags only; separate from XML tag creation |
| Category create/rename | One empty child, or rename an empty non-top leaf |
| Question deletion | Unused whole entry/all versions, with usage and cleanup guards |
| Category deletion | Empty leaf by default; empty subtree with `--recursive`; all-unused-question subtree with `--delete-unused-questions` |

The [management CLI](../scripts/manage-moodle-qbank.py) exposes all 18 endpoints
and shares the adjacent
[transport utility](../scripts/moodle_qbank_transport.py) with the smoke script;
it does not depend on the smoke script. See the
[CLI guide](../docs/moodle-qbank-cli.md) for saved previews, confirmations and
recovery. Optional writes remain default-disabled; deletion apply additionally
requires `--confirm-delete`, alongside reviewed-preview, backup and exclusive
access confirmations.

Still deferred: category moves and general description editing, direct
question creation/versioned content updates, cross-bank moves, deletion of
used questions, global tag management, and normal non-exclusive multi-writer
operation. The new source is not a production-write approval.

**Phase 1 (0.1.0, done):** paginated categories/questions, details/version
metadata and Moodle usage flags, explicit-ID XML export, and XML preview with
category subtree checks and possible-duplicate warnings. Live check:
`scripts/test-moodle-qbank-api.py` passed against the site-level system bank,
including hidden-question export rejection; previews and rejected calls left
the bank unchanged.

**Phase 2 (0.2.0, initial staging verification completed):**

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

**Phase 3 (0.5.0, experimental, initial verification completed):** A default-disabled tag
replacement operation previews a latest-version assignment and accepts only
existing standard tags. It requires exclusive access to both the bank and
relevant global tag management. The reported 2026-11-25 Moodle 5.3.1 run for
version 2026112500 passed 58 PHPUnit tests with 197 assertions on PHP 8.4.28
and MariaDB 12.3.2, including explicit REST clearing. The live tag script
passed preview, apply, replay and stale-preview rejection, and clearing;
the disposable question finished without tags. The operator then disabled
move, status and tag settings. These are sequential verification results:
actual concurrency validation and independent safety review remain
outstanding; do not enable on production. See the plugin README's
[remaining release gates](../moodle-plugins/local/bosonanceqbank/README.md#remaining-release-gates).
**Phase 3 (0.6.0, experimental, initial verification completed):** Category
creation and empty-leaf renaming use Moodle's category manager behind a
default-off feature switch, caller-bound single-use preview, shared bank
lock, transaction and audit/privacy integration. Creation adds one empty
child under an existing same-bank parent. Rename preserves category metadata
and refuses top, nonempty and non-leaf categories and banks with question-set
references. Names, sibling conflicts and stale metadata are checked;
exclusive bank access remains mandatory. On 2026-11-25, the reported category
suite passed 8 tests with 54 assertions and the full plugin suite passed
66 tests with 251 assertions on Moodle 5.3.1, PHP 8.4.28 and MariaDB 12.3.2.
The live category script passed creation, replay and stale-preview rejection,
rename and rename-back, and unchanged bank checks. Manual test-category
cleanup and feature disablement after the live run remain to be confirmed.
Concurrency validation and independent safety review remain open; these
results do not approve production writes.
Category moves/deletion and description edits, question creation and versioned
updates, and deletion preview remain deferred. Permanent deletion remains
deferred pending separate review.

**0.6.1 (`2026112502`, release hardening; Moodle PHPUnit verified, live gates pending):**
An independent review found stale caches for older moved question versions,
unpreviewed global tag creation during XML import, and import filenames
retained after privacy erasure. The fixes invalidate all moved versions,
resolve/report existing current and legacy XML tags during preview, reject
unknown tags with `xmltags`, fingerprint relevant tag metadata, verify assigned
IDs before commit, and clear filenames while preserving repeat signatures.
The upgrade also scrubs filenames on previously anonymised import records.
The reported PHPUnit upgrade to `2026112502` succeeded, followed by **70
tests with 272 assertions** passing on Moodle 5.3.1, PHP 8.4.28, MariaDB 12.3.2
and PHPUnit 11.5.56. This includes the new regressions and supersedes the
prior 66-test result.

The category live script now accepts `--concurrent` for independent-client
same-token and competing-rename races, and `--check-busy` for an operator-held
shared bank lock. It requires isolated staging, a backup and no other writers,
verifies bank metadata/counts, and stops without recovery on uncertain
responses. Local simulated-client tests cover outcomes, cancellations, lost
responses and unexpected state. Barrier dispatch is not proof of overlapping
server execution. The reported 2026-11-25 category `--concurrent` run passed
same-token and competing-rename checks, unchanged listing/count checks and
rename-back. Disposable category 7 remains for UI cleanup; successful audit
IDs were 10, 13 and 14. The output does not confirm the deployed version,
backend, isolated-staging conditions or overlapping server execution.
The subsequent `--check-busy` attempt unexpectedly created its previewed
category instead of returning `importbusy` and stopped before the races.
The output provides no holder diagnostics or created category ID; inspect
the unique previewed category name and audit before retrying.
The subsequent reported `--concurrent --check-busy` run passed `importbusy`
with unchanged bank state and a reusable token, same-token and competing
rename races, sequential replay/stale rejection and rename-back. Empty
category 12 remains for manual UI cleanup; successful audit IDs were 28, 30
and 32. Earlier test cleanup and feature disablement remain unconfirmed.
Other write-operation races, non-cooperating-writer validation and review
follow-up remain open; 0.6.1 does not approve production writes or complete the
deferred roadmap. See the plugin README for commands and cleanup requirements.

**Next release-verification step (script-only, live results pending):**
Move, status and tag scripts now accept `--concurrent`, using the same
independent-client race helper as categories. They test one token and two
competing previews, require one success and one operation-specific used/stale
rejection, verify exposed question metadata/history and bank listing/counts,
and return the entry to source, status to ready, or tags to empty after a
successful run. The winner may require a fresh confirmed restoration. A top
move source is rejected up front because the return destination is unsupported.
Tag races require at least two standard IDs and an initially untagged question.
Status/tag services must also include `list_categories` for the new state
checks, including sequential mode.

Default sequential behavior and disabled plugin feature settings are retained;
no endpoint/schema/version change is needed. Update all four scripts together.
Local simulated-client tests cover cancellation, lost responses, competing
winners, restoration and unexpected listing changes; the combined local
script suite passed 47 tests. Run each new mode
separately on isolated staging with a backup, no other writers and only its
feature temporarily enabled. Errors stop without retries or automatic recovery.
Other-write live race results, non-cooperating-writer tests and independent
review follow-up remain open.

The first reported status concurrency attempt on question 5, entry 1,
context 16, version 2 was rejected by `statusinuse` during preview, before
token creation or apply. The guard includes entry references, attempts/use
across all versions and bank-wide question-set references, not just the
selected question's reported `entryinuse`. Use a new disposable question in
a dedicated unreferenced staging bank; do not remove usage/reference records
to bypass the guard. That attempt did not verify status concurrency.

The subsequent reported status concurrency run on question 6, entry 5,
context 16/category 3, version 1 passed same-token rejection
(`statuspreviewused`), competing-preview rejection (`statusstale`), sequential
replay/stale checks and exposed metadata/listing checks. The question went
ready to draft to ready; successful audit IDs were 5 and 8. The winning
competing preview restored ready without another apply. Status's tested
cooperating workflow is verified; move/tag races and non-cooperating-writer
validation/review remain pending.

The reported tag concurrency run on that same question 6 passed same-token
rejection (`tagspreviewused`), competing subset/clear rejection (`tagsstale`),
sequential replay/stale checks, exposed metadata/listing checks and
fresh-preview verification. Tags 1 and 2 were assigned, then the winning
preview cleared them; successful audit IDs were 6 and 9. Final tags were
empty, without an additional clearing apply. Move races and non-cooperating
global-tag writer validation/review remain pending.

The reported move concurrency run on question 6, entry 5, version 1 in
context 16 passed same-token `previewused` and competing-preview
`previewstale` rejection, sequential replay/stale checks and exposed
metadata/listing checks. The entry moved category 3 to 4, then the winning
competing preview returned it to 3; audit IDs were 7 and 9. Category 2 was the
default alternate parent, not disposable content. Source/destination counts
were restored. All cooperating operation race workflows are now reported as
passing; arbitrary concurrent UI/global writers remain outside the contract.

**1.0.0 (`2026112503`, stable production baseline):** A follow-up independent
source review inspected native import/move/status/tag/category paths,
external boundaries, schema/upgrade, privacy and local Moodle core, and
verified the three prior fixes. No actionable bugs were found; the reviewer
did not rerun remote tests. This release changes metadata/packaging only,
retaining the 0.6.1 API/schema and default-disabled optional writes.

The initial production service is read/export-only, with exactly four
functions, uploads disabled, a protected restricted administrator token,
explicitly false optional flags, restore-tested backups and artifact
integrity checks. `test-moodle-qbank-api.py --read-only` adds non-mutating
response/count checks without uploads, previews or write calls.
The reproducible package builder creates a versioned ZIP and checksum,
verifies root layout/CRC/source bytes, and refuses to overwrite the existing
user-modified ZIP or any release output.

The 1.0.0 ZIP and SHA-256 manifest have been built and independently checked:
all 35 entries match current source, metadata/root/CRCs are valid, and a
second build is byte-identical. The existing unversioned ZIP is unchanged.
All 56 local script/package tests and syntax checks for 33 PHP files passed.
Exact-artifact staging rehearsal, production configuration and read-only
acceptance still require the operator; no remote deployment was performed.

Optional move/status/tag/category production writes and additive import
require separate operator approval and the documented exclusive-access
contract. This does not approve normal multi-writer operation, PostgreSQL,
generic editing or deletion. Follow the plugin README's production rollout,
rehearse the exact artifact on staging, verify the production service
allowlist independently of the smoke test, and confirm cleanup/configuration
before deployment. No remote production configuration was changed here.

**1.1.0 (`2026112504`, guarded deletion source):** Added the four question/category
deletion preview/apply endpoints, seventh preview/audit table and upgrade,
privacy integration, native cleanup/postcondition guards, and CLI irreversible
confirmations. Question deletion covers all versions of one unused entry;
category deletion initially supports empty non-top leaves only.

**1.1.1 (`2026112505`, XML tag creation source):** Supersedes 0.6.1/1.0.0's
existing-tags-only import restriction. Preview resolves existing names as
`action=reuse` and missing names as `action=create`, `id=0`, without creating
tags. Confirmed import uses Moodle's tag API to create missing ordinary
nonstandard tags in the question collection, in the same transaction as
question import. Existing tag metadata is preserved; changed metadata or
newly appearing planned names invalidate the preview. No new service functions
or schema were added.

**1.2.0 (`2026112506`, empty-subtree deletion source):** Adds optional
`recursive=true` to `preview_delete_category`, exposed as `--recursive` on
the CLI preview command only. The token binds the entire empty subtree and
apply deletes it atomically, bottom-up, with one successful audit. Category
names appear only in previews, not the plugin deletion audit/apply scope.
Default leaf request/response shapes remain compatible. No new service
functions or tables were added; complete the metadata upgrade and discard
outstanding category-deletion previews.

**1.3.0 (`2026112507`, unused-question category cascade source):** Adds
optional `deleteunusedquestions=true` to the existing category preview
endpoint and `--delete-unused-questions` to the CLI. This implies subtree mode
and is distinct from `--recursive`, which continues to reject all question-bank
entries. Preview lists every category and question entry/version with review
names/types, IDs and version metadata. Existing whole-entry guards apply to
each entry; any used/unsupported entry rejects the whole plan. Apply locks and
revalidates every category, entry, version and question, deletes all eligible
question entries first, then categories bottom-up in the same transaction.
One successful audit records IDs/counts/version metadata but no names or
question content; Moodle global tag definitions are preserved. Bounds are 100
entries and 1000 total versions per cascade. No schema/service-function
addition is needed; old category previews must be discarded after upgrade.

### Current validation evidence and remaining gates

Changes are checked against the locally downloaded
[Moodle core version](../../moodle/public/version.php):
**5.3.1 (Build: 20261112), `2026100501.00`, branch `503`**. This matches the
reported staging core release/build. Source inspection covers the actual
[category manager](../../moodle/public/question/classes/category_manager.php),
[question usage/deletion/options helpers](../../moodle/public/lib/questionlib.php)
and [tag API](../../moodle/public/tag/classes/tag.php), including top/sole-child
protection, native child reparenting, the hidden/no-op deletion fallback and
the context-object tag-assignment signature.

Validation evidence for 1.2.0 (immediately preceding source):

- **79 Python CLI/transport/script/package tests passed.**
- **31 controlled-interface PHP checks passed**, executing the actual plugin
  deleter and downloaded Moodle category manager with controlled database,
  authorization, lock and event interfaces. These cover scope, ordering,
  replay/stale rejection, occupied descendants, rollback/token reuse, protected
  categories, legacy leaf behavior and the exact 1000/1001-category boundary.
- **39 PHP syntax checks passed** on local PHP **8.5.4**; whitespace checks and
  the immutable 1.0.0 package checksum also passed.
- Database-backed Moodle regression tests for deletion, automatic XML tags
  and empty-subtree deletion were added, but **have not been run locally**.

For 1.3.0, **83 Python CLI/transport/script/package tests pass**, with syntax
validation passing for all **39 plugin PHP files**. New native
Moodle PHPUnit cases cover unused hidden/draft questions in parent/child
categories, atomic question-and-category deletion, name-free audit details,
preserved global tags, outside-category questions, and reference/content
staleness rejection. On 2026-11-26, the current 1.3.0 source was installed into
the local Moodle 5.3.1 Docker stack and the plugin upgrade completed
successfully. Its full database-backed suite passed **90 tests with 442
assertions** on Moodle 5.3.1 (Build: 20261112), PHP 8.4.28, MariaDB 12.3.2 and
PHPUnit 11.5.56. PHPUnit used the separate `phpu_` database prefix and
`/var/moodledata/phpunit`; the test database/data directory is separate from
the site's content. The focused failed-import rollback test also passed after
removing an invalid assertion against PHPUnit's raw event-trigger sink.
An independent code review found no significant actionable issues. A
read-only check of the local Moodle found no plugin functions assigned to an
external service; all optional write flags are disabled.

These checks are not a full Moodle runtime/database integration test and do
not establish isolated-staging/live-service acceptance, third-party question
type cleanup, arbitrary non-cooperating writer safety, PostgreSQL compatibility
or production approval. The repository checkout itself still lacks
`config.php` and `vendor/bin/phpunit`; these tests ran in the separate local
Docker Moodle container. The reported **70 tests / 272 assertions** and live
cooperating races apply to the earlier 0.6.1 code, not the new
deletion/tag-creation/subtree functionality.

Remaining gates:

1. Deploy the updated source and CLI to an isolated Moodle 5.3.1 staging
   instance; the local Docker stack has been upgraded, but is not a substitute
   for separately confirmed isolated staging. Refresh caches/OPcache and obtain
   fresh previews there.
2. The full database-backed suite has passed locally. Inspect the staging
   Web Services API documentation and actual service allowlist before authorizing
   any write functions.
3. Verify missing-tag creation/reuse, stale tag plans and rollback; verify
   standalone unused-question deletion, empty leaf/subtree deletion and the
   all-unused-question subtree cascade, protected/occupied/stale targets,
   replay, cleanup and audit/privacy behavior with disposable content.
4. Verify native nested transactions/events, supported database locking and
   installed third-party question-type/plugin cleanup. Retain exclusive access;
   cooperating plugin locks do not coordinate arbitrary UI/global writers.
5. Confirm disposable-content cleanup, disable temporary write flags/remove
   service permissions, and obtain separate review/operator approval before
   producing or deploying a new production-write artifact.

The local Docker Moodle source and plugin database were updated for validation.
No remote production deployment, production configuration change or live
deletion was performed.
