# bosonance-moodle-site

## Bosonance styling for Boost Union

From the repository root, run:

```bash
python scripts/build-raw-scss.py
```

The script discovers all theme directories and generates self-contained,
site-wide Raw SCSS bundles:

- `build/styles/classic-blue.scss`: restrained blue and slate styling.
- `build/styles/blue-teal.scss`: brighter blue and teal styling.

Build one theme instead of all themes with:

```bash
python scripts/build-raw-scss.py --theme blue-teal
```

All themes use the same HTML and `bosonance-` classes. To compare or switch themes,
copy **one entire bundle** into Boost Union's Raw SCSS setting, replace its
previous contents, and save. Do not paste both bundles together. The files are
ignored by Git; edit source files and regenerate them instead.
Primary Bosonance actions use the theme's blue and white; secondary hero links
are outlined. Moodle's Bootstrap primary buttons use the same primary colors,
while other Moodle controls retain their own styles. Button styles apply
site-wide, not only to Free Sample Pack pages. Blue-teal's upgrade-button text
wraps on small screens.

On the homepage slider, arrows appear on hover or keyboard focus when a mouse
is available, and remain visible on touch-capable devices. The small round
slide indicators retain a larger clickable area; only the active dot is colored.
Homepage feature, exam and advantage cards share a theme-colored border.
Update their HTML blocks in Moodle as well as the Raw SCSS when deploying them.

The previous `build/moodle-raw.scss` and `build/moodle-raw-free-sample.scss`
files, if present, are retained for comparison or rollback but are no longer
regenerated and will become stale. Use the new bundles for future changes.

### Editing styles

- `styles/shared/` contains the Boost Union overrides and shared component
  modules: base, hero, buttons, cards, banner, content and responsive styles.
  The Boost Union file's broad `!important` rules can affect unrelated Moodle
  pages; change them only after testing.
- `styles/themes/<name>/tokens.scss` defines the color and surface variables
  for one theme; an optional `overrides.scss` contains theme-specific rules.
  Every theme must define the `--bosonance-` variables used by shared styles.
- `styles/reference/` retains the original free-sample and online-test-series
  styles as references, not verified complete backups. They are not bundled.

To add a theme, create a directory under `styles/themes/` with a lowercase
hyphenated name and a `tokens.scss` defining all shared variables. Add
`overrides.scss` if needed. The builder discovers it automatically. Theme
overrides come **before** shared modules, so use appropriate selector
specificity when overriding shared rules.

Build order is Boost Union, theme tokens, optional theme overrides, then shared
modules in the order listed by `scripts/build-raw-scss.py`. Add new shared
modules to that list in their intended order. The builder reads all selected
inputs before writing any bundle; missing inputs stop the build. It concatenates
files but does not compile SCSS or resolve relative imports. Moodle compiles
the pasted bundle.

### HTML naming

Each online-test-series course directory contains `description.html` and
`general.html`. Repository file and directory names use hyphens, but Moodle
course shortnames and IDs are unchanged; do not assume they match the folder
names when mapping content for deployment.

### Deployment and validation

Back up the existing Raw SCSS and Moodle HTML snippets before deployment.
These bundles do not style old `bsp-` or `ots-` classes. Coordinate replacing
the Raw SCSS and updating all corresponding HTML snippets in Moodle, preferably
in a maintenance window: snippets can be unstyled until both are updated.
Files in this repository do not update Moodle automatically. Some snippets
retain inline colors. Purge Moodle caches if changes do not appear, and restore
both SCSS and HTML backups to roll back.

### Uploading course and category descriptions

The separate administrator-only
[`local_bosonanceqbank` plugin](moodle-plugins/local/bosonanceqbank/README.md)
provides question-bank listing/details, usage flags, XML export, and
preview-bound additive XML import with duplicate warnings for Moodle 5.3.1.
It also includes default-disabled, preview-confirmed moves, status changes
and experimental tag replacement and category creation/empty-leaf renaming.
Current source is **1.3.0 alpha**, including automatic creation of missing
XML tags on confirmed import and default-disabled deletion of unused whole
entries/all versions and empty leaf categories or explicitly selected entirely
empty subtrees and explicitly requested deletion of their unused question
entries; see the
[deletion constraints and staging upgrade](moodle-plugins/local/bosonanceqbank/README.md#unused-questionscategories-deletion).
There is no general content update or cascading deletion of used questions.
Version 1.0.0 packages the reviewed, tested scope for a
production read/export baseline; optional writes remain disabled by default.
Moodle PHPUnit passed 70 tests/272 assertions on the preceding 0.6.1 native
code, all cooperating move/status/tag/category race checks passed, and
independent review found no actionable bugs. The 1.0.0 release changes metadata
and packaging, not endpoints/schema. Arbitrary concurrent Moodle UI/global
writers are not supported. See its
[production rollout](moodle-plugins/local/bosonanceqbank/README.md#production-rollout)
for package/checksum, service restrictions, non-mutating `--read-only` smoke
checks, backups and rollback.

The previously verified 1.0.0 ZIP and SHA-256 checksum are not included in this
working tree, so those release artifacts cannot be checked or downloaded from
this checkout. The earlier package verification covered 35 files, with a
byte-identical second build; 56 local script/package tests and 33 PHP syntax
checks passed at that time. Installation rehearsal and production acceptance
remain operator steps; no production configuration or deployment was changed
here.

Use [`scripts/manage-moodle-qbank.py`](scripts/manage-moodle-qbank.py) for all
18 implemented read/export, preview/import, move, status, tag, category and deletion
operations. The [CLI usage guide](docs/moodle-qbank-cli.md) documents connection,
private output files, preview/apply confirmations, examples and recovery.
Deletion commands need the upgraded alpha source and extra `--confirm-delete`;
the CLI does not enable production writes or provide general content editing.
The CLI and smoke tests share
[`moodle_qbank_transport.py`](scripts/moodle_qbank_transport.py); copy that
utility alongside the CLI when distributing it without the repository.

`scripts/upload-moodle-html.py` uses Moodle's standard REST API to update
**course summaries and category descriptions only**. Course `general.html`
sections, homepage blocks, slides, footer and theme settings are not supported;
update those separately. The script never infers Moodle IDs from filenames.

A separate, administrator-only Moodle plugin for listing block instances and
updating HTML block content is available in
[`moodle-plugins/local/bosonanceblocks`](moodle-plugins/local/bosonanceblocks/README.md).
It must be installed and configured in Moodle; the course/category uploader
above does not use it. Use `scripts/manage-moodle-blocks.py` to list blocks or
update one HTML block through the plugin's web services:

```bash
read -rs -p "Moodle token: " MOODLE_TOKEN; echo
export MOODLE_TOKEN
python scripts/manage-moodle-blocks.py --url https://your-moodle.example list
python scripts/manage-moodle-blocks.py --url https://your-moodle.example list --json
python scripts/manage-moodle-blocks.py --url https://your-moodle.example update --id 123 --file new-content.html
```

Set `MOODLE_TOKEN` to a token for the restricted service containing
`local_bosonanceblocks_list_blocks` and
`local_bosonanceblocks_update_html`. The account must be a site administrator.
Listing in JSON includes the stored HTML content. Updates are dry-run by
default; inspect the target and proposed hashes, then add `--apply
--backup-confirmed` to update. The update reads a fresh content hash before
sending the change, but this is not an atomic lock against concurrent Moodle
editing. Use HTTPS, keep tokens secret, and test changes on staging.

Enable REST and a dedicated web service. Map generation needs
`core_course_get_courses` and `core_course_get_categories`, with permission to
view the mapped courses and categories. Uploading additionally needs
`core_course_update_courses`, `core_course_update_categories`, and update
permissions. Use a separate read-only token for mapping if possible, and keep
all tokens secret.

Create a candidate mapping using the read-only Moodle APIs:

```bash
read -rs -p "Moodle token: " MOODLE_TOKEN; echo
export MOODLE_TOKEN
python scripts/create-moodle-map.py --url https://your-moodle.example --output moodle-map.json
```

The generator calls only `core_course_get_categories` and
`core_course_get_courses`; it does not change Moodle. It finds every category
and course `description.html` beneath `course-categories`. It compares category
names ignoring case and punctuation (and treating `+` as `plus`), checks parent
categories, and matches course folder names to shortnames ignoring case and
underscore-versus-hyphen differences within the matching category. Top-level
local category names can match at any Moodle category depth. Matching names are
only suggestions: **review every ID, name and file before uploading**.

The account must be able to see all relevant categories and courses. Moodle may
omit hidden categories or reject a request when the token cannot view a course.
If there is no unique match, the script reports available IDs and writes no
map. For names that differ or collisions, create an overrides file using the
folder paths relative to `course-categories` and the IDs you have verified in
Moodle. A category override must still be under the mapped parent; a course
override must still belong to the mapped category:

```json
{
  "categories": {"online-test-series": 22},
  "courses": {"online-test-series/neet-ug/class12/courses/neet-c12-ots-en": 123}
}
```

The IDs are examples, not verified deployment mappings. Rerun with that file:

```bash
python scripts/create-moodle-map.py --url https://your-moodle.example --output moodle-map.json --overrides overrides.json
```

Fix unresolved parents first; rerun as needed to see available child IDs.
Overrides cannot select inaccessible targets or duplicate an ID. The generator
refuses to overwrite an existing output. The resulting JSON contains an
`items` array of `kind`, `id`, `expected` (exact category name or course
shortname), and repository-relative `file`. Treat the map and overrides as
site-specific information; `moodle-map.json` is ignored by Git.

After reviewing the map, run a dry run from the repository root:

```bash
python scripts/upload-moodle-html.py --url https://your-moodle.example --manifest moodle-map.json
```

To select particular targets instead, list their IDs using separate options:

```bash
python scripts/upload-moodle-html.py --url https://your-moodle.example --manifest moodle-map.json --category-ids 17 22 --course-ids 38 39
```

Omit both options to select the entire map. The two options can be combined;
they are separate because a category and a course can have the same numeric
ID. Every selected ID must exist in the manifest. Selecting a category includes
only its description, not its children or courses. The entire manifest and its
files are still validated, even when selecting a subset.

The dry run verifies the selected IDs and expected names before any writes and
prints hashes of the existing API response and proposed HTML. It does not prove
the token has permission to write every target. Moodle's read API formats the
existing HTML, so this is **not an exact backup or rollback copy**. Take and
verify an independent full database and Moodle data backup.

`--backup-confirmed` is required with `--apply`. It confirms that you have
already taken and verified that backup; the script does **not** create, check,
or restore it. Without `--apply`, the script remains a dry run even if
`--backup-confirmed` is present. Using `--apply` without it stops the script
before any updates.

After reviewing the mappings and dry-run output, apply in a maintenance window:

```bash
read -rs -p "Moodle upload token: " MOODLE_TOKEN; echo
export MOODLE_TOKEN
python scripts/upload-moodle-html.py --url https://your-moodle.example --manifest moodle-map.json --apply --backup-confirmed
unset MOODLE_TOKEN
```

For a subset, include the same `--category-ids` and/or `--course-ids` used in
the dry run when applying. Without those options, all mapped targets are updated.
Updates are sent one at a time, not as one transaction. If an update fails or
times out, earlier updates may already be applied and the current result may be
unknown. Check Moodle before retrying and restore from your backup if needed.
Only HTTPS is accepted, and redirects are refused. Test on staging before
production; the script does not deploy anything until run with `--apply`.

Test each theme on a staging site before production: check homepage slides,
course and category pages, static pages, mobile layouts, keyboard focus and
color contrast. Also confirm that the Moodle editor retains the custom classes.
Run the local build tests with:

```bash
python -B -m unittest discover -s tests
```