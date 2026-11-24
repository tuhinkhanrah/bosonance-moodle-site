# Bosonance question-bank web services

This Moodle 5.3.1 local plugin implements phases 1 and 2 of the
[question-bank API plan](../../../plan/question-bank-api-plan.md): read-only
listing, details and export, plus a **preview-bound, additive XML import**.
It also includes default-disabled, preview-confirmed single-entry moves,
single-version status changes and experimental tag replacement. There is **no general update or permanent
delete endpoint**. An import only
adds new questions and missing categories; it never changes or deletes
existing questions and never edits existing categories.

## Installation and access

1. Back up the Moodle database and data directory and test on a staging copy.
2. Copy this directory to `public/local/bosonanceqbank` in the Moodle 5.3
   checkout. Complete the Moodle upgrade through the administrator interface
   or its CLI. Do not edit Moodle core.
3. Enable web services and REST, create a restricted custom service, and add
   only the functions below. Enable **Can upload files** on the service so the
   XML can be staged. Authorize only the intended site administrator and issue
   a token for that service. Do not enable unrelated write functions for the
   same service/token.
4. Require HTTPS at the Moodle site/reverse proxy, protect tokens, and avoid
   putting tokens in URLs, logs, or source control. This plugin does not
   configure TLS. Verify the functions in Moodle's Web Services API
   Documentation before using them.

Upgrading from 0.1.0 creates two tables (previews and the import audit log),
changes `preview_xml` from `read` to `write` (it now records a preview token),
and adds `import_xml`. After the upgrade, add `import_xml` to the custom
service; omit it to keep the service read-only.

Version 0.3.0 adds a move-preview/audit table and two move functions, disabled
by default. Do not enable them on production until their PHPUnit and live
staging tests pass.

Version 0.4.0 adds a status-preview/audit table and two status functions,
disabled by default.

Version 0.5.0 adds a tag-preview/audit table and two experimental tag
functions, disabled by default and not yet verified on a Moodle installation.

Every endpoint requires a site administrator, an explicitly selected module
context belonging to a Question bank or quiz, and `moodle/question:viewall`
there. Category/question IDs must belong to that exact context; parent-course
and other-bank IDs are not accepted. Preview and import additionally require
`moodle/question:add`, plus `moodle/question:managecategory` if a category
must be created. Import also requires the **Import questions** question-bank
plugin (`qbank_importquestions`) to be enabled.

Responses may contain unpublished question content and answers. Use a
dedicated service account and handle exports securely. The plugin never logs
question content. It stores preview tokens (only as hashes) and an import
audit trail; see [Privacy](#privacy).

## Functions

Names below have the prefix `local_bosonanceqbank_`.

| Function | Type | Parameters | Result |
| --- | --- | --- | --- |
| `list_categories` | read | `contextid`, optional `offset=0`, `limit=50` | `total`, category `items`: ID, parent ID, name, direct entry count |
| `list_questions` | read | `contextid`, `categoryid`, optional `offset=0`, `limit=50`, `status=ready`, `qtype`, `search`, `tag` | `total`, latest-version metadata `items` |
| `get_question` | read | `contextid`, version-specific `questionid`, optional `includecontent=false`, history `offset=0`, `limit=50` | Question metadata, `inuse`, `entryinuse`, `versiontotal`, paginated `versions`, optional `content` |
| `export_xml` | read | `contextid`, array `questionids` | `xml`, byte `sha256`, `questioncount` |
| `preview_xml` | write | `contextid`, allowed-root `categoryid`, `draftitemid`, root-level `filename` | `sha256`, `questioncount`, category mappings, `warnings`, `warningshash`, `previewtoken`, `expires`, `canapply`, `requiresduplicateoverride` |
| `import_xml` | write | `previewtoken`, `warningshash`, optional `allowduplicate=false` | `importid`, `sha256`, `questioncount`, `duplicateoverride`, final category IDs, created question IDs |
| `preview_move` | write | `contextid`, latest `questionid`, `expectedversion`, `targetid` | Source, destination, entry, all versions, single-use token and expiry |
| `move_question` | write | `previewtoken`, `exclusive` | Move audit ID, source, destination, entry and versions |
| `preview_status` | write | `contextid`, latest `questionid`, `expectedversion`, `expectedstatus`, `targetstatus` | Entry, version, old/new status, single-use token and expiry |
| `change_status` | write | `previewtoken`, `exclusive` | Audit ID, entry, version and old/new status |
| `preview_tags` | write | `contextid`, latest `questionid`, `expectedversion`, ordered array `tagids` | Entry, version, old/new tags, single-use token and expiry |
| `change_tags` | write | `previewtoken`, `exclusive` | Audit ID, entry, version and old/new tags |

Offsets are non-negative; page sizes must be 1-100. Question listing excludes
descendant categories and uses the highest version number of each entry,
then applies the status filter (`ready`, `draft`, `hidden`, or `all`). A latest
draft does not fall back to an older ready version. Search is a literal
substring of the stored name/text; tag is an exact stored tag name.

`questionid` identifies a single version; `entryid` identifies its stable
question-bank entry. Listing does not include text/answers. Details include
stored text/general feedback only with `includecontent=true`; use XML export
for type-specific answer data and embedded files. Usage flags come from
Moodle's usage checks, not a guarantee that a question is safe to delete.
History returns version metadata, not a full audit log.

Export accepts 1-100 distinct IDs from the selected bank and rejects hidden
versions rather than silently skipping them. It uses Moodle's XML exporter
and includes category paths. To export a category, page through
`list_questions` and explicitly select IDs; it is not an atomic bank snapshot.
The returned XML is limited to 10 MiB and is not a database/data backup.

## Moving one question-bank entry

The move functions are **disabled by default**. After testing on staging,
enable them by setting `$CFG->local_bosonanceqbank_enablemoves = true` in
Moodle's `config.php`, and add both functions to the restricted service.
Removing the setting disables both functions. Each preview writes a temporary
record but does not move a question.

`preview_move` requires the latest question ID and its expected version, plus
an existing destination category in the same bank. The response identifies
the source, destination, entry, and **every version** that will move. Review
these before calling `move_question` with its single-use token and
`exclusive=true`. Tokens expire after 30 minutes. The move is transactional
and emits Moodle's `question_moved` event; the successful preview record is
kept as an audit record. To reverse a move, preview and confirm a new move to
the original category.

This first operation intentionally refuses cross-bank moves, the top category,
the same source and destination, parent/child questions, in-use questions,
any bank containing question-set references, out-of-context question tags,
and ID-number collisions. It
requires Moodle's `qbank_bulkmove` plugin, question move permission and
destination add permission. Apply supports MariaDB/MySQL and PostgreSQL.

**Exclusive access is an operational requirement, not a lock enforced against
Moodle's UI or other services.** Stop all other writes to the bank from before
preview until apply finishes. The plugin serializes its own imports and moves
and locks relevant database rows during apply, but cannot ensure other
Moodle code cooperates or detect every concurrent change. The caller must
explicitly confirm exclusivity; do not use the endpoint if it cannot be
ensured. Preview checks and row locks do not replace a backup.
If the call fails or its response is lost, check the entry and audit record
before retrying: an error after a database commit may still mean it moved.

## Changing one question version's status

The status functions are **disabled by default**. On staging only, set
`$CFG->local_bosonanceqbank_enablestatus = true;` and add both functions to the
restricted service. Remove the setting after testing. `preview_status`
requires the latest question ID, its expected version and current status,
and a different target status: `ready`, `draft`, or `hidden`. The preview
changes no question and identifies the single version affected. `change_status`
requires its single-use, 30-minute token and `exclusive=true`.

The operation requires edit permission and the `qbank_editquestion` plugin.
It rejects parent/child questions, entries with question references or attempts,
entries with any version in use, and banks with question-set references. It
rechecks state and permissions inside a transaction and keeps a successful
audit record. Ready and draft changes use
Moodle's status service. Moodle's service does not accept hidden; for hidden,
the plugin updates only the version status and mirrors the service's cache
invalidation and `question_updated` event. It never calls Moodle's delete
helper, which can permanently delete unused questions.

Stop other writes to the bank from before preview until apply finishes.
Exclusive access is a caller declaration, not a lock against Moodle UI or
other services. Status affects question selection and availability; do not
enable this operation for real content until staging verification and a
separate safety review. If a call fails or its response is lost, inspect the
status and audit before retrying; do not assume it rolled back.

## Replacing tags on one question version (experimental)

The tag functions are **disabled by default** and have not yet passed Moodle
PHPUnit or live staging tests. Do not use them on production. After copying the
plugin and upgrading Moodle, run the focused PHPUnit suite before any live
testing. For controlled staging testing only, set
`$CFG->local_bosonanceqbank_enabletags = true;` and add `preview_tags` and
`change_tags` to the restricted service. Remove the setting after testing.

`preview_tags` takes the latest question ID and expected version and an ordered
list of up to 50 distinct IDs of existing standard tags in the question tag
collection. An empty list clears assignments; the list replaces rather than
adds to existing tags. The operation refuses nonstandard, foreign-context or
user-specific existing tags, unavailable tags, parent/child questions, direct
question references and any bank with question-set references. It requires
Moodle's question tag capability and enabled tagging and `qbank_tagquestion`.
Review the old and new lists; `change_tags` needs the single-use token and
`exclusive=true`. Apply supports MariaDB/MySQL and PostgreSQL.

Stop other writes to the bank **and all relevant global tag-management changes**
from before preview until apply finishes. Exclusivity is a caller declaration:
neither the plugin lock nor its row locks prevent all other Moodle writers.
Keep a verified backup and use a disposable question and standard tags. If a
response is lost or an error occurs, inspect the assignments and audit record
before retrying; do not assume a rollback.

## Importing XML safely

Importing is always two calls:

1. Upload the XML to your own draft area (`webservice/upload.php`), then call
   `preview_xml`. Nothing in the question bank changes. Review `categories`
   (`reuse` or `create`) and every entry in `warnings`.
2. Call `import_xml` with the returned `previewtoken` and `warningshash`.
   Passing the hash is your acknowledgement of exactly those warnings.

### Validation

Preview reads only a file owned by the caller in `user/draft`, at
`/filename`. It accepts UTF-8 `<quiz>` documents with only `category`,
`multichoice` and `matching` records, at most 10 MiB and 1000 combined
records. It rejects DTDs, other encodings, malformed XML, unsupported record
types, invalid category paths, missing required question fields, invalid
matching pairs or multichoice fractions, and invalid base64 files. It then
runs Moodle's own XML reader on the file without saving anything, and rejects
the file if that reader reports errors, reads a different number of questions
(`xmlmoodle`), or finds an answer fraction that is not an allowed Moodle grade
(`xmlgrade`). If the XML embeds `<file>` elements, Moodle's reader stages them
in the caller's own draft area, which Moodle cleans up automatically.

Category directives must start with `$module$/top/` and must stay inside the
selected category's subtree (`xmlscope`). They are resolved only inside the
explicit bank context, never used to choose another context. Questions before
the first directive go into the selected category. Missing categories are
created on import; their parents are implied by `create`. For a file with
several top-level trees, select the bank's top category.

### Warnings and repeat imports

Every generated file in the chapter and vault repositories carries category
records, so re-importing a file **adds a second copy** of every question into
the same categories. Warnings make that visible before anything is written:

- `exact_repeat` (always first, `index` 0): the same bytes (SHA-256) were
  already imported into the same bank and selected category. Lists up to 20
  question IDs from the earlier imports. `requiresduplicateoverride` is true
  and `import_xml` fails with `duplicateimport` unless `allowduplicate=1`.
  The audit record keeps `duplicateoverride=1`.
- `file_imported_elsewhere` (`index` 0): the same bytes were imported into this
  bank under a different selected category.
- `duplicate_in_file`: repeated name/type at the same destination in the file.
- `possible_existing_duplicate`: name/type matches existing versions in the
  destination category, including hidden or older versions; reports a count
  and up to 20 matching version IDs.

Only `exact_repeat` blocks. The others are heuristics: renamed questions,
edited files and overlapping combined/individual exports can escape them. Do
not import every XML found in an output directory; some files are OCR
metadata, and combined exports overlap their constituent files.

### What import guarantees

`import_xml` refuses to write unless all of these hold:

- The token is valid, unused, unexpired (30 minutes) and belongs to the
  caller. Tokens are stored only as SHA-256 hashes and are single-use.
- `warningshash` equals the hash of the preview's warnings (`acknowledgement`).
- The draft file still has the previewed SHA-256, and a fresh analysis gives
  the same result: same categories, warnings and destination-subtree state
  (category names/parents, entry counts, newest entry). Any change since the
  preview, including another import, gives `previewstale`; preview again.
- No other import into the same bank is running (`importbusy`); imports into
  one bank are serialized with a lock.
- It is not inside another database transaction.

Moodle's XML importer then runs inside one database transaction with
`matchgrades=error` and stop-on-error. Existing categories are never edited
(core would otherwise copy an empty description/idnumber from the file).
Before commit the plugin checks that exactly the previewed number of
questions was created, the subtree gained exactly that many entries, and every
question has the expected type and destination category. Any failure,
including a database error mid-file, rolls back **everything**: no questions,
no categories, no audit record, and the token stays unused (`importfailed`
reports the reason). On success it stores an audit record and triggers
Moodle's standard *Questions imported* event.

There is no delete endpoint. Undo an unwanted import in the question bank UI,
using the returned question and category IDs.

## Privacy

`local_bosonanceqbank_preview` holds the caller, bank, category, draft
reference, file name/hash and token hash of each preview; expired previews are
removed whenever a new preview is created. `local_bosonanceqbank_import`
holds the audit trail: caller, bank, category, file name/hash, created question
IDs and whether a duplicate was overridden. The privacy provider exports both.
On deletion it removes the user's previews and anonymises (sets `userid` to 0)
their import records, which are kept so repeat detection keeps working.
Unused move previews are deleted and successful move audit records are
anonymised. Status and tag previews and audit records follow the same policy.

## Live API test script

`scripts/test-moodle-qbank-api.py` exercises the Phase 1 and 2 functions against
an installed site and prints a PASS/FAIL/SKIP summary (exit code 0 only when
nothing failed). By default it changes nothing in the bank. It covers paging,
all status filters, qtype/search/tag filters, question details with and
without content, export hash/count/paths, preview of a generated XML file, an
export round trip that must flag every question as a possible existing
duplicate, `duplicate_in_file`, missing category paths reported as `create`,
the preview token fields, and negative cases (page limits, invalid status,
unknown/other-bank IDs, hidden export, malformed/DOCTYPE/unsupported XML, path
traversal, out-of-scope paths, missing draft files, malformed or unknown
import tokens, and a wrong warnings hash). It records every category's entry
count before previewing and fails if anything changed.

Real imports run only when both `--import-categoryid` and `--allow-import` are
given. The script then creates one uniquely named child category
(`qbank-api-import-<random>`) under that category and checks: preview then
import creates exactly the previewed questions; a used token is rejected; a
re-preview flags `exact_repeat`; importing it without `allowduplicate` is
rejected; importing it with `allowduplicate=1` works; a preview taken before
that import is then rejected as stale; and no other category changed. It
leaves four test questions in that category and prints its ID: delete the
category in the question bank UI afterwards.

Preview tests upload small XML files into the token user's own draft area
through `webservice/upload.php`, so the service needs **Can upload files**.
Question text is never printed.
The script does not yet test the move functions.

For a separate opt-in live move test, use
`scripts/test-moodle-qbank-move.py` with an explicitly chosen disposable
question, its source, a destination, and a distinct empty alternate category
in the same bank. It reviews two previews, moves the entry, checks replay and
stale-token rejection, then previews and moves it back. It asks for
confirmation before each move and stops on errors without attempting recovery.
Run `python3 scripts/test-moodle-qbank-move.py --help` for the required
arguments. Enable the move feature and service functions on staging first;
maintain exclusive bank access throughout the test.

After the 0.4.0 PHPUnit suite passes on staging, the opt-in
`scripts/test-moodle-qbank-status.py` tests a disposable question's
ready -> draft -> hidden -> ready round trip and replay/stale rejection.
It requires the two existing test scripts in the same directory, explicit
opt-in, and interactive confirmation for each change. Run with `--help`
for arguments. Stop on any failure and inspect the question and audit before
retrying; no automatic recovery is attempted.

```powershell
$env:MOODLE_TOKEN = '<token>'
python -B scripts\test-moodle-qbank-api.py --url https://moodle.example.com `
    --contextid 123 --categoryid 456 --other-contextid 789
# Also run real imports into a throwaway child of category 456:
python -B scripts\test-moodle-qbank-api.py --url https://moodle.example.com `
    --contextid 123 --categoryid 456 --import-categoryid 456 --allow-import
```

`--contextid` is the module context of a Question bank or quiz. Pass a
non-top `--categoryid` that contains questions to enable the subtree-scope
test, plus `--other-contextid` for the cross-bank isolation test. `--xml`
selects the file to preview (default: a generated vault sample). Run it on
staging first.

## Tests and release gate

After installing in a Moodle development checkout and initializing Moodle's
**separate** PHPUnit database/data directory, run its configured PHPUnit
runner against `public/local/bosonanceqbank/tests`, specifying
`--test-suffix _test.php` when passing the directory directly. PHPUnit's
default directory discovery may otherwise find no tests. Never initialize
PHPUnit against the production database/data directory.

Tests cover parser limits and unsafe XML, context/user restrictions, listing
and content selection, duplicate warnings, exports, preview tokens, import
success and audit, warning acknowledgement, single-use and expired/foreign
tokens, exact-repeat blocking and override, stale previews, and full rollback
of a failure in the middle of a file.

Phase 2 staging verification: Moodle 5.3.1 PHPUnit passed 35 tests with 96
assertions. The live script passed 37 checks with 6 skips on an empty staging
bank, then 36 checks with 3 skips against the created test category. These
runs do not verify the new move functions.

Version 0.3.0 staging PHPUnit passed 44 tests with 128 assertions on Moodle
5.3.1 with MariaDB. A live staging test moved one disposable entry and back,
and verified replay and stale-preview rejection. This does not establish
safety under concurrent writes or cover every move edge case.
Do not assume the exclusive-access confirmation prevents other users or
services from writing concurrently.

On Moodle 5.3.1 staging, one disposable question passed the live status
round trip ready -> draft -> hidden -> ready, including replay and stale-preview
rejection. Attempts on other questions correctly blocked previews. The 0.4.0
staging PHPUnit suite passed 51 tests with 168 assertions on MariaDB.
Concurrency and other live edge cases remain untested.

Live verification of 0.1.0 (Moodle 5.3.1, site-level system bank): the live
API test script passed 33 checks with 0 failures; previews and failed calls
left all category and question counts unchanged. A hidden question was later
created for the hidden-export check. Cross-bank isolation (`--other-contextid`)
and all 0.2.0 import behaviour still need a live run.
