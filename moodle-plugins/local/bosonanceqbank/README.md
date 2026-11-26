# Bosonance question-bank web services

This Moodle 5.3.1 local plugin implements phases 1 and 2 of the
[question-bank API plan](../../../plan/question-bank-api-plan.md): read-only
listing, details and export, plus a **preview-bound, additive XML import**.
It also includes default-disabled, preview-confirmed single-entry moves,
single-version status changes, experimental tag replacement, and experimental
category creation/empty-leaf renaming. The **1.1.0 alpha source** adds guarded,
default-disabled permanent deletion of unused whole entries and empty leaf
categories. Version 1.1.1 also previews and creates missing XML tags on confirmed
import. Version 1.2.0 adds recursive deletion of entirely empty category
subtrees; 1.3.0 adds an explicit mode to delete all unused question entries
and versions in a category subtree before deleting the categories. There is
**no general content update or cascading deletion of used questions**. An import only
adds new questions and missing categories; it never changes or deletes
existing questions and never edits existing categories.

## Source candidate status

The verified 1.0.0 ZIP remains unchanged and contains no deletion API.
Current source is a 1.3.0 alpha candidate (`2026112507`), not the previously
verified production artifact. The source was installed and upgraded in the
local Moodle 5.3.1 Docker stack on 2026-11-26; its database-backed PHPUnit
suite passed 90 tests with 442 assertions. This validates the current source
against the local Moodle/MariaDB runtime, but does not replace isolated-staging
acceptance or live service checks with disposable content. The immutable
1.0.0 artifact and its checksum remain unchanged.

Version 1.1.1 supersedes the earlier existing-tags-only import restriction.
XML previews now report `action=reuse` or `action=create` and `id=0` for
missing tags. Confirmed imports create planned missing names as ordinary
nonstandard tags in the question tag collection through Moodle's API, in the
same transaction as the questions. No new schema or service functions are
added by 1.1.1. Complete the metadata upgrade, refresh caches/OPcache as in the
deployment procedure, and discard outstanding import previews. The 1.0.0 ZIP
still enforces the old existing-tags-only rule; use updated source for this
behavior and rerun the Moodle tests on staging.

Version 1.2.0 adds optional `recursive=true` to the existing category-deletion
preview function, with no new tables or service functions. Update the CLI
alongside the PHP source and complete the metadata upgrade. Discard outstanding
category-deletion previews after upgrading; leaf-only request/response shapes
remain compatible. Recursive deletion still requires database-backed staging
acceptance and is not included in the immutable 1.0.0 ZIP.

Version 1.3.0 adds optional `deleteunusedquestions=true` to that same preview
function; it implies whole-subtree mode and must not be combined with
`recursive=true`. The preview contains every category and question entry/version
to be removed. Existing question deletion guards apply to every version; one
used or unsupported entry rejects the whole operation. Apply deletes all
eligible question entries and then the categories in one transaction. Limits
are 100 entries and 1000 total question versions in addition to the 1.2.0
category/file limits. This does not add a service function or table. Update the
CLI and plugin, complete the metadata upgrade, and discard outstanding deletion
previews; this alpha change still requires isolated-staging and live service
validation before any write feature is enabled.

## Unused questions/categories deletion

Use the [CLI deletion guide](../../../docs/moodle-qbank-cli.md#permanently-delete-unused-entries-or-empty-leaf-categories)
for previews, irreversible confirmations, constraints and recovery.
`$CFG->local_bosonanceqbank_enabledeletions` defaults to false. Leave it false
on production, including after deploying new source.

Question deletion uses `question_delete_question` on **all versions of one
entry**, with edit permission on each and enabled `qbank_deletequestion`.
It rejects any attempts (including previews), entry references, Moodle/plugin
usage, bank-wide question-set references, parent/child questions, ambiguous
version mappings or missing types. It verifies entry/version/question and
standard question-file removal, then explicitly removes tag assignments
through Moodle's API without deleting global tags. A native hidden/no-op
fallback rolls back rather than reporting success.

Category deletion uses Moodle's category manager with
`qbank_managecategories` and managecategory permission. By default it requires a
non-top leaf with **no entries or children**, retains sole-child-of-top
protection and verifies its parent/siblings survive unchanged. Optional
`recursive=true` previews every descendant and refuses any question-bank entry.
Optional `deleteunusedquestions=true` instead includes every entry/version in
the whole descendant tree. Every entry passes the same usage/reference,
attempt, type, question-parent, file, tag and permission guards as standalone
whole-entry deletion; the operation fails closed if any one is used or
unsupported. Cascade mode also requires enabled `qbank_deletequestion`, in
addition to `qbank_managecategories`. The preview binds the full question/category scope and displays
names only for operator review. Apply deletes all question entries first and
then categories bottom-up through native helpers in one transaction, with one
name/content-free audit. Any failure rolls back the entire operation; changed
questions/categories invalidate the preview. Global tag definitions survive.
External dependencies maintained outside Moodle's checked usage APIs cannot
be ruled out; exclusive staging and third-party review remain required. No
question relocation or deletion of used questions is offered.

Previews are caller- and kind-bound, expire after 30 minutes and are single-use.
Apply requires exclusive access from before preview through completion and
uses the shared bank lock, row locks and a transaction on MySQL/MariaDB or
PostgreSQL. Fresh snapshots include question options/answers/hints, assignments
and bank-context file metadata. Limits are 100 versions per entry, 100 question
entries and 1000 total versions per category cascade, 1000 total categories
per subtree, 1000 direct category
siblings and 10000 bank-context file records. All bank file metadata is
fingerprinted because answer/hint/type-specific item IDs are not necessarily
question IDs; even unrelated file metadata changes in that bank invalidate
question previews. The lock does not coordinate Moodle UI/global writers.

Deletion audits retain IDs, version/status/timestamp metadata and actor, not
names, question text, answers or files. Privacy erasure removes unused rows
and anonymises successful actors. Core helpers own type-specific cleanup and
post-commit observers; verify installed third-party types/plugins on staging.
There is no automatic retry or undo; uncertain outcomes require inspection
of target absence and the `deletionid` audit, with consistent backup restore
as the recovery option.

After deploying the candidate source to isolated staging, run:

```bash
docker compose exec -T moodle php admin/cli/upgrade.php --non-interactive
docker compose exec -T moodle php public/admin/tool/phpunit/cli/util.php --upgrade
docker compose exec -T moodle php vendor/bin/phpunit \
  --test-suffix _test.php public/local/bosonanceqbank/tests
```

Only then enable the flag and authorize the required deletion preview/apply
pair for disposable staging checks. Disable/remove them afterwards.

## Installation and access

For production, follow [Production rollout](#production-rollout), which uses
a read/export-only service with uploads disabled. The steps below describe
the staging/import service, not the production baseline.

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
service; omit it to prevent question imports.
A read/export-only service also omits `preview_xml`, which records
tokens, and disables uploads; use the production allowlist below.

Version 0.3.0 adds a move-preview/audit table and two move functions, disabled
by default. Do not enable them on production until their PHPUnit and live
staging tests pass.

Version 0.4.0 adds a status-preview/audit table and two status functions,
disabled by default.

Version 0.5.0 adds a tag-preview/audit table and two experimental tag
functions, disabled by default. Later cooperating concurrency results and
independent review are recorded below; production write restrictions remain.

Version 0.6.0 (`2026112501`) adds a category-preview/audit table and two
experimental category functions, disabled by default. Initial Moodle PHPUnit
and live category verification passed. Later race/lock checks and independent
review are recorded below; production write restrictions remain.

Version 0.6.1 (`2026112502`) hardens the existing scope without adding tables:
XML previews report existing question tags and reject implicit global tag
creation; moves invalidate every affected version's cache; privacy erasure
also clears import filenames. These fixes follow an independent review.
The upgrade also clears filenames from previously anonymised import audits.
Deploy the source directory, not an older ZIP, and rerun the updated Moodle
tests before any live checks. The 0.6.0 results do not validate 0.6.1.
Regenerate outstanding XML previews after upgrading: their fingerprints now
include resolved tag metadata.

Version 1.0.0 (`2026112503`, stable baseline) changes release metadata and
provides a verified deployment package; it adds no API or schema changes
over the tested 0.6.1 source. The production baseline is an administrator-only
read/export service. Optional move/status/tag/category writes remain
default-disabled and are not enabled by this release. Stable maturity does
not approve experimental features or concurrent Moodle UI/global writers.
Older installations still run any pending earlier table/privacy migrations;
upgrade does not reset existing feature flags or revoke old service tokens.

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

## Production rollout

### Package and supported baseline

The verified target is Moodle 5.3.1, PHP 8.4.28 and MariaDB 12.3.2. The reported
0.6.1 Moodle suite passed 70 tests/272 assertions; category, status, tag and
move cooperating-request checks passed. A follow-up independent source
review inspected native write paths, access boundaries, schema/upgrade,
privacy and the prior cache/tag/filename fixes, finding no actionable bugs.
That review did not rerun remote tests. PostgreSQL and arbitrary concurrent
UI/global writers are not production-verified.
Local release validation passed 56 Python script/package tests and syntax
checks for all 33 plugin PHP files.

The release ZIP has one top-level `bosonanceqbank/` directory, containing the
plugin source and Moodle tests. The older unversioned ZIP is deliberately
not overwritten. From this repository root, build once and verify:

```bash
python3 -B scripts/build-moodle-qbank-package.py
cd moodle-plugins/local
sha256sum -c bosonanceqbank-1.0.0.zip.sha256
```

The builder verifies paths, CRCs and source bytes; it fixes ZIP timestamps,
ordering and permissions for reproducibility on the same toolchain. It
rejects symlinks/unexpected hidden paths and never overwrites an existing
package/checksum. Use `--output` with a different ZIP filename for a new
candidate. A checksum proves archive integrity, not authenticity: obtain
the ZIP and checksum through a trusted channel.

### Production configuration

1. Restore-test a consistent backup of the database, Moodle data directory,
   and current plugin source on an isolated copy. Retain the previous source
   and release metadata. Clean up only confirmed disposable staging content.
   Do not delete default or parent categories because their direct entry
   count is zero: context 16's category 2 is a default parent of category 3,
   not disposable test content.
2. Rehearse installation of this exact ZIP on that copy. Upgrade Moodle's
   separate PHPUnit database and rerun the plugin suite before deployment.
   Never point PHPUnit at production data.
3. Use a maintenance window for deployment. Stop cron, other services and
   administrator writes as needed; maintenance mode alone does not stop every
   writer. Explicitly disable all optional flags in production, even if they
   were enabled in staging:

   ```php
   $CFG->local_bosonanceqbank_enablemoves = false;
   $CFG->local_bosonanceqbank_enablestatus = false;
   $CFG->local_bosonanceqbank_enabletags = false;
   $CFG->local_bosonanceqbank_enablecategories = false;
   ```

4. Install the ZIP through Moodle's plugin installer, or deploy its extracted
   `bosonanceqbank/` directory as `public/local/bosonanceqbank`. Do not leave an
   old source directory beside a differently named new copy. For the Docker
   layout used in the reported tests, run:

   ```bash
   docker compose exec -T moodle php admin/cli/upgrade.php --non-interactive
   docker compose exec -T moodle php admin/cli/purge_caches.php
   ```

   Reload PHP/web workers as appropriate for the deployment so OPcache cannot
   serve old code; a CLI cache purge alone does not reset every web worker.

5. Create a restricted, administrator-only **production read-only service**
   with exactly these functions:
   `local_bosonanceqbank_list_categories`,
   `local_bosonanceqbank_list_questions`,
   `local_bosonanceqbank_get_question`, and
   `local_bosonanceqbank_export_xml`. Leave **Can upload files** disabled.
   Do not include any preview/apply/import functions. Remove or disable
   staging services/tokens on production; an omitted function on one service
   does not revoke another service's access.
6. Require HTTPS, choose an appropriately expiring token, apply IP
   restrictions where feasible, and store credentials securely. Never log
   token URLs, request bodies containing tokens, exports or question content.
   Do not reuse a staging token as the production credential.

### Non-mutating acceptance check

Use the production read-only token through `MOODLE_TOKEN` and replace the
host/IDs below with an authorized bank/category containing exportable
questions. Run the updated local script from this checkout; Python scripts
are not included in the Moodle installer ZIP:

```bash
python3 -B scripts/test-moodle-qbank-api.py --url https://moodle.example.com \
  --contextid 123 --categoryid 456 --read-only
```

This mode calls only the four read/export functions, checks their responses
and bank counts before/after, and never uploads or invokes previews/writes.
It rejects combining `--read-only` with import options. It does not audit the
server's service allowlist; verify step 5 independently. Run it during the
quiet acceptance window so unrelated edits do not invalidate its snapshots.
Review any skipped read/export checks as unverified, not proof of acceptance.
The default API test includes draft uploads and preview records, so use
`--read-only` on production, not the default or mutation test modes.

Confirm the installed plugin shows 1.0.0/version `2026112503`, upgrade has
finished, the read-only check passes, and logs show no unexpected exceptions.
Then reopen the site and monitor errors, response latency, preview/audit
growth and token use.

### Write approval and recovery

Additive XML import is a separate, explicitly approved workflow, not part of
the read-only service. Use a separate restricted service/token with only its
required read, preview/import and upload permissions, a verified backup, and
exclusive bank and relevant global tag-management access from preview through
apply. Review targets/warnings and use a fresh preview; never blindly retry
after an error or lost response.

Keep optional move/status/tag/category features off on production by default.
Their successful staging checks and source review do not authorize normal
multi-writer production use. Any later write approval must explicitly define
and enforce the exclusive-access window and accept the documented operation
limits; otherwise leave the flags and write service access disabled.

On an unexpected write response, stop writers, disable the affected feature
and service/token, and inspect the affected IDs plus audit before retrying.
An error after commit can still mean the operation happened. Reversal needs
another fresh preview where supported; imports/category creation have no
automatic undo. For rollback after an upgrade or unwanted mutation, restore
the consistent database/data/source backup under maintenance. Merely copying
older PHP over a newer schema/version is not a reliable downgrade.

## Functions

Names below have the prefix `local_bosonanceqbank_`.

| Function | Type | Parameters | Result |
| --- | --- | --- | --- |
| `list_categories` | read | `contextid`, optional `offset=0`, `limit=50` | `total`, category `items`: ID, parent ID, name, direct entry count |
| `list_questions` | read | `contextid`, `categoryid`, optional `offset=0`, `limit=50`, `status=ready`, `qtype`, `search`, `tag` | `total`, latest-version metadata `items` |
| `get_question` | read | `contextid`, version-specific `questionid`, optional `includecontent=false`, history `offset=0`, `limit=50` | Question metadata, `inuse`, `entryinuse`, `versiontotal`, paginated `versions`, optional `content` |
| `export_xml` | read | `contextid`, array `questionids` | `xml`, byte `sha256`, `questioncount` |
| `preview_xml` | write | `contextid`, allowed-root `categoryid`, `draftitemid`, root-level `filename` | `sha256`, `questioncount`, category mappings, `tags` (existing ID or zero, name, standard flag, collection ID, reuse/create action), `warnings`, `warningshash`, `previewtoken`, `expires`, `canapply`, `requiresduplicateoverride` |
| `import_xml` | write | `previewtoken`, `warningshash`, optional `allowduplicate=false` | `importid`, `sha256`, `questioncount`, `duplicateoverride`, final category IDs, created question IDs |
| `preview_move` | write | `contextid`, latest `questionid`, `expectedversion`, `targetid` | Source, destination, entry, all versions, single-use token and expiry |
| `move_question` | write | `previewtoken`, `exclusive` | Move audit ID, source, destination, entry and versions |
| `preview_status` | write | `contextid`, latest `questionid`, `expectedversion`, `expectedstatus`, `targetstatus` | Entry, version, old/new status, single-use token and expiry |
| `change_status` | write | `previewtoken`, `exclusive` | Audit ID, entry, version and old/new status |
| `preview_tags` | write | `contextid`, latest `questionid`, `expectedversion`, ordered array `tagids`, or `clear=true` | Entry, version, old/new tags, single-use token and expiry |
| `change_tags` | write | `previewtoken`, `exclusive` | Audit ID, entry, version and old/new tags |
| `preview_category` | write | `contextid`, `categoryid`, `action=create` or `rename`, `name` | Selected category, parent, old/new names, single-use token and expiry |
| `change_category` | write | `previewtoken`, `exclusive` | Audit ID, created/renamed category ID, selected category, parent and old/new names |
| `preview_delete_question` | write | `contextid`, latest `questionid`, `expectedversion` | Name, whole entry, complete version list/count, kind-bound token and expiry |
| `delete_question` | write | `previewtoken`, `exclusive` | `deleted=true`, `deletionid`, entry and deleted version metadata |
| `preview_delete_category` | write | `contextid`, `categoryid`, optional `recursive=false`, `deleteunusedquestions=false` | Category and token; recursive scope, or question cascade with every category/entry/version for review |
| `delete_category` | write | `previewtoken`, `exclusive` | `deleted=true`, `deletionid`, exact saved scope and counts; names/content omitted |

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

The tag functions are **disabled by default**. PHPUnit, cooperating live
concurrency checks and independent source review passed; production write
approval remains separate. Keep them off on production by default. After copying the
plugin and upgrading Moodle, run the focused PHPUnit suite before any live
testing. For controlled staging testing only, set
`$CFG->local_bosonanceqbank_enabletags = true;` and add `preview_tags` and
`change_tags` to the restricted service. Remove the setting after testing.

`preview_tags` takes the latest question ID and expected version and an ordered
list of up to 50 distinct IDs of existing standard tags in the question tag
collection. An empty list clears assignments; REST clients must instead omit
`tagids` and explicitly pass `clear=1` because form encoding cannot represent
an empty array. Omitting both or combining `clear=1` with nonempty `tagids` is
rejected. The list replaces rather than adds to existing tags. The operation
refuses nonstandard, foreign-context or user-specific existing tags,
unavailable tags, parent/child questions, direct question references and any
bank with question-set references. It requires
Moodle's question tag capability and enabled tagging and `qbank_tagquestion`.
Review the old and new lists; `change_tags` needs the single-use token and
`exclusive=true`. Apply supports MariaDB/MySQL and PostgreSQL.

Stop other writes to the bank **and all relevant global tag-management changes**
from before preview until apply finishes. Exclusivity is a caller declaration:
neither the plugin lock nor its row locks prevent all other Moodle writers.
Keep a verified backup and use a disposable question and standard tags. If a
response is lost or an error occurs, inspect the assignments and audit record
before retrying; do not assume a rollback.

## Creating and renaming categories (experimental)

The category feature is **disabled by default**. Moodle PHPUnit, cooperating
live race/lock checks and independent source review passed; production write
approval remains separate. Keep it off on production by default. After installing and
upgrading the plugin, run the focused PHPUnit suite on its separate test
database. For staging testing only, set
`$CFG->local_bosonanceqbank_enablecategories = true;` and add
`local_bosonanceqbank_preview_category` and
`local_bosonanceqbank_change_category` to the restricted service. It requires
`moodle/question:managecategory` in the selected bank and enabled
`qbank_managecategories`.

For `action=create`, `categoryid` is an existing parent in that exact bank;
apply creates one empty child using Moodle's category manager, with an empty
description and no ID number. For `action=rename`, `categoryid` is the target
category. Initially only an **empty, non-top leaf** is accepted, and the bank
must not have question-set references. Rename preserves parent, context,
description/format, ID number, stamp and sort order. There is no category
move, description edit, deletion or implicit question change.

Names must be nonblank, plain text, at most 255 characters, with no leading
or trailing whitespace, slashes, backslashes or control characters. The
literal name `0` is rejected because Moodle's category manager treats it as
blank. Sibling names are checked case-insensitively, including unchanged
renames; parents with more than 1000 existing children are unsupported.

Review the returned action, IDs and names before applying with
`exclusive=true`. A token is caller-bound, single-use and expires in 30
minutes. Apply rechecks permissions, category/parent/sibling metadata and
rename eligibility under the shared bank lock and database transaction,
then uses Moodle's category manager and verifies the resulting metadata.
It emits the manager's standard category-created/updated event and retains
an audit record. A changed target can produce `categorystale`,
`categoryunsupported`, `categorynameconflict` or a missing-record error;
preview again rather than retrying the old token.

Stop all other bank writes from before preview until apply finishes. This is
an operational requirement, not a lock against Moodle UI or other services.
If a call fails or its response is lost, inspect categories and audit records
before retrying. To reverse a rename, preview and confirm another rename.
Creation is additive, not automatically reversible; clean up disposable
empty categories through Moodle's UI. No deletion API is provided.

## Importing XML safely

When Moodle question tagging is enabled, both XML `tags` and legacy
`coursetags` are merged and resolved in the question tag collection.
Existing standard and nonstandard tags are reused unchanged; missing names
are previewed with `id=0`, `isstandard=false`, `action=create` and created
automatically on confirmed import. Preview creates no global tags.
Creation uses Moodle's API in the question import transaction; failures roll
back newly created definitions and questions together. Names are cleaned and
matched case-insensitively. Prefixes such as `standard:` are literal name
text, not tag-type instructions. Existing names in another collection do not
prevent creation in the question collection. With tagging disabled, Moodle's
reader ignores tag elements, as before.

Keep the bank and relevant global tag management free of other writers from
preview through import. Relevant tag metadata is fingerprinted and the
assigned tag IDs are verified before commit. If a planned missing name is
created or existing metadata changes before apply, obtain a fresh preview.
The plugin's bank lock does not serialize global tag management, other-bank
imports creating these names, or Moodle UI.

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
  the same result: same categories, warnings, resolved tags and destination-subtree state
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
question has the expected type and destination category, and assigned tag IDs
match the preview. Any failure,
including a database error mid-file, rolls back **everything**: no questions,
no categories, no audit record, and the token stays unused (`importfailed`
reports the reason). On success it stores an audit record and triggers
Moodle's standard *Questions imported* event.

The 1.0.0 artifact has no delete endpoint. Undo an unwanted import in the question bank UI,
using the returned question and category IDs.

## Privacy

`local_bosonanceqbank_preview` holds the caller, bank, category, draft
reference, file name/hash and token hash of each preview; expired previews are
removed whenever a new preview is created. `local_bosonanceqbank_import`
holds the audit trail: caller, bank, category, file name/hash, created question
IDs and whether a duplicate was overridden. The privacy provider exports both.
On deletion it removes the user's previews, clears import filenames, and
anonymises (sets `userid` to 0) their import records. File hashes, destinations
and created IDs are kept so repeat detection keeps working.
The 0.6.1 upgrade clears filenames already retained on `userid=0` import
audits; it does not remove their repeat signatures or created question IDs.
Unused move previews are deleted and successful move audit records are
anonymised. Status, tag and category previews and audit records follow the
same policy. Category records include the action, selected/parent/result
category IDs and old/new names; token and state hashes are not exported.

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

For tag verification, first install and upgrade to plugin version `2026112500`
or later, then rerun the focused PHPUnit suite. Enable the tag functions only
on staging and use an unused, disposable latest-version question with **no
existing tags**, plus existing standard tag IDs from the question tag
collection. `list_categories`, `get_question`, `preview_tags` and `change_tags` must be in the
restricted service. Maintain exclusive bank and global tag-management access
throughout. The test previews assignments, applies them, checks replay and
stale-token rejection, clears the tags, and verifies the final state with a
fresh preview. It asks for confirmation before each change and stops on any
failure without attempting recovery. Preview records and successful audit
records remain; inspect the question and audit before retrying.

```bash
export MOODLE_TOKEN='<restricted-service-token>'
python3 -B scripts/test-moodle-qbank-tags.py --url https://moodle.example.com \
  --contextid 123 --categoryid 456 --questionid 789 --tagids 11 12 --allow-tag-change
```

Replace the example IDs with staging values. Run with `--help` for arguments;
remove the feature setting after testing.

### Concurrent move, status and tag requests

The move, status and tag scripts now also accept `--concurrent`. Without it
their existing sequential workflows remain the default. They share the
category script's independent-client/barrier race helper and require exactly
one success and one expected rejection per pair:

| Operation | Same-token rejection | Competing previews | Competing rejection | Successful final state |
| --- | --- | --- | --- | --- |
| Move | `previewused` | From destination back to source, or to alternate | `previewstale` | Original source category |
| Status | `statuspreviewused` | From draft to hidden, or ready | `statusstale` | Ready |
| Tags | `tagspreviewused` | Replace full list with all but the last tag, or clear | `tagsstale` | No assigned tags |

Use an **isolated staging bank**, a verified database/data backup and an
unused disposable latest-version question. Stop every other bank writer;
for tags also stop relevant global tag-management changes. Enable only the
operation currently being tested, using its existing feature setting, and
remove the setting afterward:

| Operation | Temporary feature setting | Required restricted-service functions |
| --- | --- | --- |
| Move | `$CFG->local_bosonanceqbank_enablemoves = true;` | `list_categories`, `get_question`, `preview_move`, `move_question` |
| Status | `$CFG->local_bosonanceqbank_enablestatus = true;` | `list_categories`, `get_question`, `preview_status`, `change_status` |
| Tags | `$CFG->local_bosonanceqbank_enabletags = true;` | `list_categories`, `get_question`, `preview_tags`, `change_tags` |

Service function names have the `local_bosonanceqbank_` prefix. Status and tag
tests now need `list_categories` even in sequential mode to verify bank
listing/counts. Update all four local scripts together. Adding these modes was
a script-only step for 0.6.1; the 1.0.0 metadata upgrade is described above.

Replace these host/IDs with staging values and supply `MOODLE_TOKEN`
securely, as above:

```bash
python3 -B scripts/test-moodle-qbank-move.py --url https://moodle.example.com \
  --contextid 123 --sourceid 456 --targetid 457 --alternateid 458 \
  --questionid 789 --allow-move --concurrent

python3 -B scripts/test-moodle-qbank-status.py --url https://moodle.example.com \
  --contextid 123 --categoryid 456 --questionid 789 \
  --allow-status-change --concurrent

python3 -B scripts/test-moodle-qbank-tags.py --url https://moodle.example.com \
  --contextid 123 --categoryid 456 --questionid 789 \
  --tagids 11 12 --allow-tag-change --concurrent
```

For moves, source, target and alternate must be distinct, same-bank, non-top
categories; alternate must be empty. A top source is rejected before moving
because Moodle would refuse the return destination. The entire entry,
including all versions, moves. Status must initially be ready. Tags must
initially be empty; concurrency requires at least two distinct existing
standard tag IDs. These scripts do not create test categories.

`statusinuse` can reject a ready question even when `get_question` reports
`entryinuse=false`: the status guard additionally blocks entry references,
attempts on any version, and any question-set references in the selected bank.
Use a fresh disposable question in a dedicated staging Question bank with no
quiz/random-question references. Do not delete references or attempts merely
to bypass the safeguard.

Each test races a single token first, verifies sequential replay/stale
rejection, then races two competing previews. The winning destination/status/
tag set is verified and restored with a fresh confirmed preview if needed.
Depending on the winner, concurrency mode makes two or three successful
changes. Move and status confirmations use `MOVE`; tags use `TAGS`. All modes
verify exposed question metadata/history and category listing/counts, and
print successful audit IDs without printing preview tokens or question
content.

Every dispatched pair is allowed to finish before outcomes are checked.
On errors or uncertain responses, the script stops without retries or
automatic restoration: inspect actual state/audit before doing anything else.
Passing barrier-dispatched requests does not prove overlapping server
execution or protection against non-cooperating Moodle UI/global writers.
The combined local script suite passed 47 tests, including simulated-client
coverage of these modes. Status and tag concurrency passed in the reported
runs below; move concurrency still needs live results.
The first reported status concurrency attempt on question 5 (entry 1,
context 16, version 2) was rejected with `statusinuse` during the initial
preview, before a token or status change. This verifies the refusal path,
not either concurrency pair.

The subsequent reported status run on question 6 (entry 5, context 16,
category 3, version 1) passed both independent-client pairs: one success and
one `statuspreviewused`, then one success and one `statusstale`. Sequential
replay/stale rejection and exposed metadata/listing checks passed. The winning
competing change returned draft to ready, so no extra restoration was needed;
successful audit IDs were 5 and 8. The question finished ready. This does not
verify move/tag races or non-cooperating writers.

The reported tag concurrency run on the same question 6 (entry 5, context 16,
category 3, version 1) passed same-token rejection (`tagspreviewused`),
competing subset/clear rejection (`tagsstale`), sequential replay/stale
checks, exposed metadata/listing checks and fresh-preview verification.
Tags 1 and 2 were assigned; the winning competing preview cleared them,
requiring no extra apply. Successful audit IDs were 6 and 9, and final tags
were empty. Move races and non-cooperating global-tag writers remain unverified.

For version 0.6.0, `scripts/test-moodle-qbank-category.py` creates a uniquely
named empty child of an explicitly selected parent, verifies replay and
stale-preview rejection, then renames the new category and renames it back.
It checks that previews/rejected calls change nothing and that all other
category metadata exposed by listing and direct question counts stay
unchanged. It prompts before each apply, stops on any error without recovery,
and prints the created category ID for manual staging cleanup.
The service needs `list_categories` and both category functions. After the
updated PHPUnit suite passes, enable only the category feature on staging,
keep a verified backup and maintain exclusive bank access:

```bash
python3 -B scripts/test-moodle-qbank-category.py --url https://moodle.example.com \
  --contextid 123 --parentid 456 --allow-category-change
```

Use the same securely supplied `MOODLE_TOKEN` as the other live scripts.
Remove the category feature setting after testing. See the 0.6.0 results
below; the earlier 0.5.0 runs do not verify these new operations.

### Concurrent category requests and bank-lock contention

After the **updated** PHPUnit suite passes, use an isolated staging bank with
a verified backup and no other writers. Add `--concurrent` to race two
independent HTTP clients at a barrier: applying the same creation token must
produce one success and one `categorypreviewused`; two competing rename
previews must produce one success and one `categorystale`. The script verifies
the listing/counts, renames the disposable category back, prints successful
audit IDs, and leaves the empty category for manual UI cleanup.

```bash
python3 -B scripts/test-moodle-qbank-category.py --url https://moodle.example.com \
  --contextid 123 --parentid 456 --allow-category-change --concurrent
```

Barrier dispatch does not guarantee overlapping execution on the server.
Record the backend and check category/audit rows independently; this tests
cooperating plugin requests, not concurrent Moodle UI writers.

To verify explicit lock contention as well, add `--check-busy`. The script
pauses after its initial previews. Only at that pause, run this command in a
second terminal on the staging server (replace `123` with the same context):
`--check-busy` does not start the holder automatically. The holder must use
the same Moodle deployment and shared lock backend as the REST requests.
For `--contextid 16`, the resource must be `context_16`, not `context_123`.

```bash
docker compose exec -T moodle php -r '
define("CLI_SCRIPT", true);
require "config.php";
$factory = \core\lock\lock_config::get_lock_factory("local_bosonanceqbank_import");
$lock = $factory->get_lock("context_123", 0);
if (!$lock) {
    throw new RuntimeException("Could not acquire staging bank lock");
}
try {
    echo "LOCK HELD resource=context_123 factory=", get_class($factory), "\n";
    flush();
    sleep(45);
} finally {
    $lock->release();
    echo "LOCK RELEASED\n";
}
'
```

Within 15 seconds of `LOCK HELD`, confirm the script's prompt. Its request
must return `importbusy` after the plugin's 30-second wait with the bank
unchanged; the original token must remain usable for the subsequent create.
The default HTTP timeout is 60 seconds; `--check-busy` rejects timeouts of
30 seconds or less. Late confirmation can let a create succeed instead:
any unexpected response stops the test, so inspect state/audit before retrying.
The holder releases automatically after 45 seconds; let it finish.
If the busy-check apply succeeds, a category was created: locate it by the
unique name displayed in the preview and inspect its audit before any retry.
Check the holder's resource, backend and timing; unexpected success alone
does not establish a plugin locking defect.

On any lost response or error, all dispatched requests are allowed to finish,
then the test stops without retries or automatic recovery. Inspect actual
category state and audit records. Remove the category feature setting and
manually delete only the disposable empty category after successful testing.

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

On 2026-11-25, the reported version 2026112500 PHPUnit database upgrade
succeeded and the full plugin suite passed **58 tests with 197 assertions**
on Moodle 5.3.1 (Build: 20261112), PHP 8.4.28, MariaDB 12.3.2 and PHPUnit
11.5.56. This includes the explicit REST clear change and supersedes the
earlier version 2026112401 run (57 tests, 193 assertions).

The reported live tag script completed preview, assignment, replay rejection
(`tagspreviewused`), stale-preview rejection (`tagsstale`), unchanged
assignments after rejected calls, and clearing verified by a fresh preview.
The disposable question finished without tags. Move, status and tag feature
settings were then disabled by the operator. These results establish the
tested sequential workflow, not safety under simultaneous writes or
production approval.

For version 0.6.0 (`2026112501`), the reported 2026-11-25 category suite passed
**8 tests with 54 assertions**, followed by the full plugin suite passing
**66 tests with 251 assertions** on the same Moodle/PHP/MariaDB/PHPUnit
versions listed above. This includes the corrected format-type and
database-value comparisons.

The reported live category script passed creation of one empty child,
replay rejection (`categorypreviewused`), stale-preview rejection
(`categorystale`), rename and rename-back, and unchanged bank checks after
previews and rejected calls. The empty test category remains for manual
cleanup; category feature disablement after this run has not yet been
confirmed. These are sequential workflow results, not concurrency
verification or production approval.

On 2026-11-25, the operator reported a successful `--concurrent` category
run: independent same-token requests produced one success and one
`categorypreviewused`; competing rename previews produced one success and
one `categorystale`. Listing/count checks, sequential replay/stale rejection
and rename-back also passed. Disposable category 7 remains for manual UI
cleanup; successful audit IDs were 10, 13 and 14. This output does not report
the deployed plugin version/backend or establish overlapping server execution.
It does not verify `--check-busy`, other write races or non-cooperating writers.

The reported 0.6.1 (`2026112502`) PHPUnit upgrade succeeded and the full
suite passed **70 tests with 272 assertions** on Moodle 5.3.1, PHP 8.4.28,
MariaDB 12.3.2 and PHPUnit 11.5.56. This supersedes the 0.6.0 suite result.
The subsequent `--concurrent --check-busy` run stopped at the busy check:
apply succeeded instead of returning `importbusy`. Its uniquely named empty
category must be inspected and manually cleaned up; the output gives no
created category ID or holder diagnostic. Lock contention was unverified at that point,
and that run did not proceed to its concurrency checks.

The subsequent reported `--concurrent --check-busy` run passed: `importbusy`
left the bank unchanged and the token usable, same-token requests produced
one success and one `categorypreviewused`, and competing renames produced
one success and one `categorystale`. Sequential replay/stale rejection,
listing/count checks and rename-back also passed. Disposable empty category
12 remains for manual UI cleanup; successful audit IDs were 28, 30 and 32.
This verifies the tested cooperating category workflow and explicit bank-lock
contention, not other write races or protection against Moodle UI writers.
Cleanup of earlier test categories and feature disablement remain unconfirmed.

The reported move concurrency run on question 6 (entry 5, version 1, context
16) passed same-token rejection (`previewused`), competing-preview rejection
(`previewstale`), sequential replay/stale checks and exposed metadata/listing
checks. It moved category 3 to 4 and the winning competing preview returned
it to 3; successful audit IDs were 7 and 9. Category 2 was the alternate,
not a disposable category. Final source/destination counts were restored.

### Remaining release gates

Latest 1.3.0 local validation (2026-11-26): the Moodle plugin was upgraded in
the local Docker stack and the full database-backed suite passed **90 tests
with 442 assertions** on Moodle 5.3.1 (Build: 20261112), PHP 8.4.28, MariaDB
12.3.2 and PHPUnit 11.5.56. The 83 local qbank CLI/package tests and PHP syntax
checks for all 39 plugin files also passed. PHPUnit used a separate `phpu_`
database prefix and `/var/moodledata/phpunit`; no live deletion scenarios or
remote production changes were performed. The local Moodle has no plugin
functions assigned to an external service, and all optional write flags are
disabled. An independent code review found no significant issues; it is not
production approval. These results supersede the earlier statement that the
1.3.0 database-backed tests had not run locally, but do not constitute
live-service or isolated-staging acceptance.

The native 0.6.1 functional suite, all cooperating live operation race
workflows and category bank-lock check passed. Independent review follow-up
found no actionable bugs, including in the three prior fixes. The 1.0.0
baseline release changes metadata/packaging, not native behavior or schema.

- **Deployment acceptance:** rehearse the exact release artifact and its
  upgrade with restore-tested backups, then follow [Production rollout](#production-rollout).
  Production configuration/cleanup and read-only acceptance still require
  operator confirmation; no remote production settings were changed here.
- **Non-cooperating writers:** editing, new versions, usage/reference and
  global tag-management changes during apply remain unsupported/unverified.
  The shared plugin lock does not serialize Moodle UI or other services.
  Never infer multi-writer safety from cooperating-request tests; retain the
  exclusive-access contract or keep writes disabled.
- **Other backends/scope:** current verification covers MariaDB, not
  PostgreSQL. Generic question creation/versioned updates, broader category
  management and deletion preview remain deferred. Permanent deletion is
  not part of this release.

The category script now supports independent-client races and an
operator-held bank-lock check, with local simulated-client regression tests.
The operator reported passing independent-client category races and
operator-held bank-lock contention above. Confirm the deployed
version, backend and isolated-staging conditions before approving writes.
Together with the reported move/status/tag runs, these cover cooperating
request races, not non-cooperating writers. Stable maturity applies to the
production baseline and does not expand this boundary.

Live verification of 0.1.0 (Moodle 5.3.1, site-level system bank): the live
API test script passed 33 checks with 0 failures; previews and failed calls
left all category and question counts unchanged. A hidden question was later
created for the hidden-export check. This historical run predates Phase 2;
see the Phase 2 results above. Hidden-export and cross-bank checks skipped
on the later staging bank still need completion.
