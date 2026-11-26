# Question-bank management CLI

[`manage-moodle-qbank.py`](../scripts/manage-moodle-qbank.py) provides all
18 currently implemented `local_bosonanceqbank` REST operations. It is an
operations tool, not a disposable-content live-test script.

It uses Python 3 and the standard library only. Run from this repository
checkout, or copy the CLI and
[`moodle_qbank_transport.py`](../scripts/moodle_qbank_transport.py) into the
same directory. That utility owns the shared Moodle API client and API contracts;
both the CLI and the smoke-test script use it. The CLI does **not** import or
require the smoke-test script. The original 14 commands work with 1.0.0;
the four deletion commands require **1.1.0 or newer alpha source**.
Automatic creation of missing XML tags requires **1.1.1 / `2026112505`**.
Opt-in recursive empty-category deletion requires **1.2.0 / `2026112506`**.
Deleting a subtree with all its unused question entries requires
**1.3.0 / `2026112507`**.
Deploy the updated PHP source and complete Moodle's upgrade; updating the
Python CLI alone does not change server behavior. The immutable 1.0.0 ZIP
has neither deletion endpoints nor automatic XML tag creation.
New deletion functionality needs database-backed staging validation before
production approval.

When copying the smoke-test script elsewhere, also copy the same transport
utility beside it. Existing live-test scripts retain their smoke-test helper
dependency and therefore need that utility too. Loading the utility performs
no tests, network requests or CLI execution.

**Not implemented:** category moves, cascading deletion of used questions,
general question text/answer/grade editing, global tag management, or
automatic bank/context discovery. Import adds questions; it does not update
existing ones.

## Connection and permissions

Set the Moodle base URL and the token for a restricted service:

```bash
export MOODLE_URL=https://feynmaninstitute.com
read -r -s -p 'Moodle service token: ' MOODLE_TOKEN
printf '\n'
export MOODLE_TOKEN

python3 -B scripts/manage-moodle-qbank.py --help
python3 -B scripts/manage-moodle-qbank.py preview-xml --help
```

The token is read only from `MOODLE_TOKEN`: never put it in a command-line
argument, URL, committed file or log. Requests use HTTPS POST and refuse
redirects. For local development only, plain HTTP is accepted for `localhost`,
loopback IPv4, and `::1`; every non-loopback host must use HTTPS. Use Moodle's
configured base URL, including any installation subdirectory, rather than an
alternate host that redirects.

`--url` overrides `MOODLE_URL`; `--timeout` overrides the default 60 seconds.
These global options go **before** the subcommand:

```bash
python3 -B scripts/manage-moodle-qbank.py \
  --url https://feynmaninstitute.com --timeout 120 \
  list-categories --contextid 16
```

Every API operation requires an authorized site administrator and an exact
question-bank/quiz **module context ID**, not a course ID. IDs are never
inferred. Examples below use earlier staging IDs; verify and replace them
before use. `questionid` identifies a specific question version;
`expectedversion` is its latest version number, not the entry ID.

For production, retain the four-function read/export service, uploads
disabled and optional write flags false. See the plugin's
[production rollout](../moodle-plugins/local/bosonanceqbank/README.md#production-rollout).
The CLI does not change service permissions or enable plugin features.

On an approved isolated staging bank or separately approved write window,
authorize only the required preview/apply pair. Import also requires the
service's **Can upload files** setting and enabled `qbank_importquestions`.
Optional operations require their corresponding configuration flag:

| Pair | Flag to enable explicitly in Moodle configuration |
|---|---|
| `preview-move`, `move-question` | `local_bosonanceqbank_enablemoves` |
| `preview-status`, `change-status` | `local_bosonanceqbank_enablestatus` |
| `preview-tags`, `change-tags` | `local_bosonanceqbank_enabletags` |
| `preview-category`, `change-category` | `local_bosonanceqbank_enablecategories` |
| `preview-delete-question`, `delete-question`, `preview-delete-category`, `delete-category` | `local_bosonanceqbank_enabledeletions` |

Moodle still enforces the endpoint-specific capabilities, enabled question
bank plugins and usage/scope guards. Setting a flag or CLI confirmation does
not bypass those checks.

## Output and safety

- Read commands return formatted JSON on stdout unless `--output` is supplied.
- `get-question` does not request question text by default. Content requires
  **both** `--include-content` and `--output`; answers are available in XML export.
- Export and preview commands require an output file. Files are exclusively
  created, never overwritten, with mode `0600` on POSIX subject to the user's
  umask. Use a private directory/restrictive ACL on other platforms.
- XML export verifies the UTF-8 bytes against Moodle's SHA-256, the reported
  count and the actual XML question-record count. XML is not printed.
- A preview writes a private JSON envelope containing the secret preview token,
  normalized site URL, operation, request parameters and preview response.
  Console preview JSON omits the secret. These are **CLI preview files**:
  arbitrary/raw API JSON is not accepted by apply commands.
- Preview files contain sensitive metadata and a usable confirmation token.
  Do not commit, share or print their complete contents. Outputs may contain
  unpublished questions/answers; protect them and remove them when no longer needed.
- Previews do not change question-bank content, but they **are not read-only
  requests**: they store token records. XML preview first uploads a draft and
  can stage embedded files; Moodle later cleans up unused drafts.
- Every apply requires `--confirm --backup-confirmed --exclusive`.
  Permanent deletion additionally requires `--confirm-delete`.
  `--confirm` means the printed preview and all warnings were reviewed;
  `--backup-confirmed` means an independent restore-tested backup exists.
  `--exclusive` declares that no other relevant writers run from **before
  preview until apply completes**. For tags/import, include relevant global
  tag management. The CLI does not acquire a global Moodle lock or enforce
  exclusivity against UI/other services.
- Apply checks the saved operation, exact normalized base URL and expiry.
  Moodle additionally binds tokens to the caller and validates their state.
  Use the same Moodle user; tokens expire after 30 minutes and are single-use.
  Local checks are not a substitute for server authorization or stale checks.
- Apply results are JSON with the native audit ID. `--output` can preserve
  them privately. An existing output path prevents any upload/apply request.
- There are **no automatic retries, re-previews, restoration or cleanup of
  bank content**. If an apply response is lost, malformed, interrupted or
  cannot be saved, it may already have committed. Inspect Moodle state/audit
  before deciding what to do. The original preview file is retained; do not
  blindly rerun it.

Exit status is `0` for success, `1` for API/local/response errors, `2` for
invalid CLI arguments and `130` for cancellation. Diagnostics and saved-file
messages go to stderr. API errors report their error code without printing
server debug payloads. A timeout longer than the plugin's 30-second lock wait
is recommended; a timeout is not proof of rollback.

## Read categories and questions

```bash
# All category pages, including direct entry counts.
python3 -B scripts/manage-moodle-qbank.py list-categories \
  --contextid 16 --all

# All matching latest versions in this exact category, not its descendants.
python3 -B scripts/manage-moodle-qbank.py list-questions \
  --contextid 16 --categoryid 3 --status all --all

# Filters and an explicit page.
python3 -B scripts/manage-moodle-qbank.py list-questions \
  --contextid 16 --categoryid 3 --status ready --qtype multichoice \
  --search "village" --tag tag-001 --offset 0 --limit 100

# Metadata, usage flags and the first page of version history.
python3 -B scripts/manage-moodle-qbank.py get-question \
  --contextid 16 --questionid 6

# Save stored text/feedback without printing it.
python3 -B scripts/manage-moodle-qbank.py get-question \
  --contextid 16 --questionid 6 --include-content --output question-6.json
```

Pagination supports `--offset` and `--limit` (1-100, default 50).
`get-question` pages version history; inspect `versiontotal` to request further
pages. `--all` is available for the two list commands and cannot accompany a
nonzero offset. It checks totals and duplicate IDs across pages but does not
provide database snapshot isolation; avoid concurrent changes.

Usage flags are not permission to delete, nor a guarantee of status/move
eligibility. Additional guards include references and random-selection scopes.

## Export XML

```bash
python3 -B scripts/manage-moodle-qbank.py export-xml \
  --contextid 16 --questionids 6 --output question-6.xml
sha256sum question-6.xml
```

Pass 1-100 distinct version-specific IDs after `--questionids`. Hidden versions
are refused by Moodle. The file includes category paths and embedded files.
For more than 100 questions, select explicit batches and use different files;
this CLI does not silently merge exports or select questions for you.

## Preview and apply XML import

```bash
python3 -B scripts/manage-moodle-qbank.py preview-xml \
  --contextid 16 --categoryid 3 --file bank.xml --output import-preview.json

# Only after reviewing categories/tags/duplicate warnings and establishing
# backup and exclusive access before preview:
python3 -B scripts/manage-moodle-qbank.py import-xml \
  --preview-file import-preview.json \
  --confirm --backup-confirmed --exclusive --output import-result.json
```

The local file must be nonempty and at most 10 MiB. Upload uses a generated
safe filename in a fresh draft item; the preview hash is checked against the
actual local bytes. Subsequent apply uses that preview, not a fresh upload.
Changing the local file afterwards does not change the already staged import.

Moodle validates UTF-8 `<quiz>` XML with category directives and multichoice/
matching questions, at most 1000 combined records and at least one question.
Paths such as `$module$/top/Physics/Mechanics` reuse/create named categories
but must match the real hierarchy and remain inside the selected category's
subtree. Existing categories are not renamed.

When question tagging is enabled, XML `<tags>` and legacy `<coursetags>` are
merged. Names already in the **question tag collection** are reused; missing
names are created automatically **on confirmed import**, not during preview.
The preview's `tags` list shows each deduplicated name, `collectionid`,
`isstandard`, and `action`:
- `reuse`: `id` is the existing tag ID.
- `create`: `id=0`; Moodle will create an ordinary **nonstandard** tag.

Names are cleaned and matched case-insensitively using Moodle's rules.
For example, `standard:neet_ug` is a literal name, not an instruction to
create a standard tag. Existing standard/nonstandard flags are unchanged;
tag names in another collection do not count as existing question tags.
The separate tag-replacement commands still accept only existing standard
tags; import does not relax their eligibility rules.
No separate tag-creation command or confirmation flag is required: review
the tag plan before acknowledging the import preview.

Creation and question import share one database transaction. Failed imports
roll back newly created tag definitions as well as the questions. A planned
missing tag appearing, or existing tag metadata changing, between preview
and apply invalidates the preview. Keep relevant global tag management and
other imports that could create these names exclusive throughout the window.
With question tagging disabled, Moodle ignores XML tag elements as before.
After upgrading, obtain a **fresh preview** rather than applying an older one.

The CLI sends the saved `warningshash` to acknowledge exactly that preview's
warnings. An exact repeat is blocked locally unless `--allow-duplicate` is
also supplied; Moodle independently enforces that rule. Use that option only
when deliberately adding another copy. Import never updates existing questions.

## Move an entry within its bank

```bash
python3 -B scripts/manage-moodle-qbank.py preview-move \
  --contextid 16 --questionid 6 --expectedversion 1 --targetid 4 \
  --output move-preview.json
python3 -B scripts/manage-moodle-qbank.py move-question \
  --preview-file move-preview.json --confirm --backup-confirmed --exclusive
```

The selected question must be the latest version. All versions of its entry
move together. Both categories must be eligible in the same bank. Cross-bank,
in-use, parent/child question and random-question-scope cases are conservatively
refused. To move back, obtain and apply a **new** reverse preview; the CLI does
not restore automatically.

## Change latest-version status

```bash
python3 -B scripts/manage-moodle-qbank.py preview-status \
  --contextid 16 --questionid 6 --expectedversion 1 \
  --expectedstatus ready --targetstatus draft --output status-preview.json
python3 -B scripts/manage-moodle-qbank.py change-status \
  --preview-file status-preview.json --confirm --backup-confirmed --exclusive
```

Statuses are `ready`, `draft` or `hidden`. Get the actual current status/version
first. In-use questions and other unsupported reference cases are refused.
Hiding is **not permanent deletion**. Restoring a status requires a new preview.

## Replace or clear standard tags

```bash
python3 -B scripts/manage-moodle-qbank.py preview-tags \
  --contextid 16 --questionid 6 --expectedversion 1 --tagids 1 2 \
  --output tags-preview.json
python3 -B scripts/manage-moodle-qbank.py change-tags \
  --preview-file tags-preview.json --confirm --backup-confirmed --exclusive

# A separate preview/apply to clear assignments.
python3 -B scripts/manage-moodle-qbank.py preview-tags \
  --contextid 16 --questionid 6 --expectedversion 1 --clear \
  --output clear-tags-preview.json
python3 -B scripts/manage-moodle-qbank.py change-tags \
  --preview-file clear-tags-preview.json --confirm --backup-confirmed --exclusive
```

`--tagids` accepts 1-50 distinct existing standard question tag IDs, obtained
from Moodle tag management. Replacement is the whole assignment set, not an
append. `--clear` is mutually exclusive with `--tagids` and sends the explicit
REST clear flag without an empty-array parameter. No global tags are created.

## Create a category or rename an empty leaf

```bash
# categoryid is the existing parent for create.
python3 -B scripts/manage-moodle-qbank.py preview-category \
  --contextid 16 --categoryid 3 --action create --name "Mechanics" \
  --output create-category-preview.json
python3 -B scripts/manage-moodle-qbank.py change-category \
  --preview-file create-category-preview.json --confirm --backup-confirmed --exclusive

# Replace 123 with resultcategoryid from the successful creation response.
python3 -B scripts/manage-moodle-qbank.py preview-category \
  --contextid 16 --categoryid 123 --action rename --name "Mechanics revision" \
  --output rename-category-preview.json
python3 -B scripts/manage-moodle-qbank.py change-category \
  --preview-file rename-category-preview.json --confirm --backup-confirmed --exclusive
```

Creation adds one empty child with an empty description and no ID number.
Rename requires an empty, non-top leaf and preserves its parent/other metadata.
Names must meet Moodle/plugin rules, including nonblank plain text and no
slashes or control characters. This is not general category editing.

## Permanently delete unused entries or empty leaf categories

**Irreversible, disabled by default, experimental.** Deploy the candidate
source, complete Moodle's upgrade and authorize only the required deletion
preview/apply pair. In isolated staging, explicitly set:

```php
$CFG->local_bosonanceqbank_enabledeletions = true;
```

Question deletion requires enabled `qbank_deletequestion` and edit permission
on every version. Select the **latest question ID and version**; deletion
removes the **whole entry, every version, answers/options/files and tag
assignments**, not only the selected version. Global tag definitions survive.
Hidden/draft versions are included. Every version must be unused: any
question attempt (including preview attempts), entry reference, bank-wide
question-set/random-selection reference, or Moodle/plugin usage report blocks
deletion. Parent/child questions and missing question types are unsupported.

Category deletion requires enabled `qbank_managecategories` and category
management permission. By default only empty non-top leaves are eligible.
With `preview-delete-category --recursive`, the selected category and **all
descendants** are eligible only if every category has zero question-bank
entries, including hidden/draft entries and entries without visible versions.
Moodle's top-category and sole-child-of-top protections remain enforced.
There is **no question cascade in empty-subtree mode or automatic relocation**.

To delete a subtree that contains questions, use the separate
`--delete-unused-questions` preview option. This explicitly requests deletion
of **every entry and every version in the selected category and all
descendants**, followed by bottom-up category deletion. Every question version
must pass the same edit-permission, attempt, reference, usage, supported-type,
parent/child, options, tag and file-cleanup checks as standalone whole-entry
deletion. Any used or unsupported question rejects the entire operation; the
plugin never skips a question or partially deletes the branch. All question and
category deletions share one transaction and one audit. Existing global tag
definitions survive. “Unused” is limited to references and usage detectable
through Moodle and plugin checks; third-party plugins may maintain external
dependencies, so staging and exclusive writer control remain essential.
Cascade mode additionally requires enabled `qbank_deletequestion`.

Use fresh, verified disposable staging IDs, not these placeholders:

```bash
python3 -B scripts/manage-moodle-qbank.py preview-delete-question \
  --contextid 16 --questionid 123 --expectedversion 1 \
  --output delete-question-preview.json
python3 -B scripts/manage-moodle-qbank.py delete-question \
  --preview-file delete-question-preview.json \
  --confirm --backup-confirmed --exclusive --confirm-delete \
  --output deleted-question.json

python3 -B scripts/manage-moodle-qbank.py preview-delete-category \
  --contextid 16 --categoryid 124 --output delete-category-preview.json
python3 -B scripts/manage-moodle-qbank.py delete-category \
  --preview-file delete-category-preview.json \
  --confirm --backup-confirmed --exclusive --confirm-delete \
  --output deleted-category.json

# Delete an entirely empty branch without deleting children individually.
# Replace 125 with the verified subtree root; do not select the bank's top.
python3 -B scripts/manage-moodle-qbank.py preview-delete-category \
  --contextid 16 --categoryid 125 --recursive \
  --output delete-subtree-preview.json
python3 -B scripts/manage-moodle-qbank.py delete-category \
  --preview-file delete-subtree-preview.json \
  --confirm --backup-confirmed --exclusive --confirm-delete \
  --output deleted-subtree.json

# Explicitly delete every eligible unused question and the full category tree.
# Do not combine this option with --recursive.
python3 -B scripts/manage-moodle-qbank.py preview-delete-category \
  --contextid 16 --categoryid 125 --delete-unused-questions \
  --output delete-unused-questions-preview.json
python3 -B scripts/manage-moodle-qbank.py delete-category \
  --preview-file delete-unused-questions-preview.json \
  --confirm --backup-confirmed --exclusive --confirm-delete \
  --output deleted-unused-questions.json
```

Review the name, IDs, version list and count printed by preview. A successful
response has `deleted=true` and `deletionid`; a hidden-status fallback is
**not** success. Fresh metadata/options (including calculated datasets), tags and file-record changes invalidate
previews. Recursive previews show `recursive=true`, `categorycount` including
the root, and the complete `categories` list with IDs, parents and names.
The apply token binds that exact subtree; there is no recursive apply override.
Added, removed, moved or renamed descendants invalidate the preview.
Apply deletes children before parents in one transaction, with one successful
audit for the whole branch; a failure rolls back all its deletions and leaves
the token unused. Apply/audit category lists retain only IDs and parent IDs,
not names. Outside parents and siblings must survive unchanged.
For `--delete-unused-questions`, the preview additionally shows `entrycount`,
total `questioncount` (versions), and every `questionentries` item including
all version IDs/statuses/timestamps and names for operator review. The
successful apply response and audit preserve IDs/version metadata but omit
question/category names and content.
After upgrading, discard outstanding category-deletion previews and obtain
fresh ones. Leaf-only request/response shapes remain compatible.

Conservative limits are 100 versions per entry, 100 question entries and 1000
total versions in one question cascade, 1000 total categories in a subtree,
1000 direct category siblings and 10000 file records in the bank context.
Question previews fingerprint all
bank-context file metadata to include answer/hint and type-specific item IDs;
an unrelated file edit in that same bank therefore also requires a fresh
preview. No deleted names or question content are retained in deletion audits.

Exclude all relevant UI/services, attempts, category/tag/file management and
other writers from before preview through apply. The shared plugin lock only
coordinates cooperating plugin calls. Moodle's native helpers own
type-specific cleanup and post-commit observers; installed third-party types
and plugins require their own staging cleanup verification.
Never retry automatically after a lost response. Inspect absence and audit;
recovery requires a consistent backup restore, not another import or an undo
command. Disable the deletion flag and remove the service pair after testing.

## Errors and recovery

- `importbusy`: cooperating plugin lock could not be acquired. Ensure exclusive
  access; a token is not automatically refreshed or retried.
- Used/expired/stale preview errors: inspect state, then explicitly obtain a
  new preview if the operation is still appropriate. Never edit tokens/expiry
  in saved files to try bypassing server checks.
- `statusinuse` and move/selection-scope errors: choose eligible disposable
  staging content or resolve legitimate dependencies through Moodle. Do not
  disable the safety checks.
- `xmlscope`, invalid tag names (`xmltags`), unsupported XML or duplicate
  errors: correct the file/category selection and preview again. In 1.1.1,
  a valid missing tag name is not an error.
- `deleteinuse`, `deletecategoryoccupied`, `deleteunsupported`, `deletelimit`:
  inspect the target and its legitimate dependencies; never bypass guards.
- `deletefailed`: physical deletion postconditions failed and the database
  transaction was rolled back. Inspect native type/file cleanup and audit
  before any new preview; this is not a successful hide operation.
- A successful apply followed by a local write failure still requires
  state/audit inspection. Do not interpret CLI exit code `1` as proof that
  Moodle rolled back.

Use distinct output filenames for each run. Retain approved results securely
for reconciliation, then remove only your own explicitly identified local
preview/export files. Bank-content cleanup requires explicit operator action.
