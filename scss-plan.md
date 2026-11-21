# Bosonance site-wide SCSS branding plan

## Assessment and direction

The initial assessment below records the starting point before migration.
The repository contains 118 HTML fragments and three source SCSS files. A
repository-wide static scan found 92 HTML files with inline styles (2,038
style attributes): 76 online-test-series fragments, 12 Boost Union fragments,
and four homepage blocks. All 25 free-sample-pack fragments use `bsp-` classes.
The scan covered every HTML file; representative page families and all three
SCSS files were examined in detail. This is not a rendered or live-site audit.

Create two comparable site-wide visual presets. The online-test-series preset
uses the restrained light slate surfaces, blue primary actions, cards and
type hierarchy of `online-test-series.scss`. The free-sample preset takes its
brighter blue/teal hero and accents from `free-sample-pack.scss`. Both apply
consistently to every page, including slides; the free-sample preset is not
limited to sample pages. `scss.scss` remains the basis for Boost Union
navigation, slider and Moodle chrome, adapted to each preset.

Both presets follow the requested blue/slate direction: deep slate text
(`#0f172a`), white and soft slate surfaces (`#ffffff`, `#f8fafc`), a deep blue
primary (`#1d4ed8`, darker on hover), and restrained semantic or subject
accents. Their surfaces, gradients, accents, and component treatments may
differ to reflect the two source designs. Treat the values as a starting
palette, not a guarantee of accessibility: verify actual color pairings and
states in both presets.

All custom styling names use the `bosonance-` prefix: for example,
`.bosonance-card`, `--bosonance-primary`, `$bosonance-primary`, and `bosonance-`
animation names. Moodle and Bootstrap's own classes and selectors are not
renamed. The generated bundles no longer contain `bsp-` or `ots-` selectors.

## Todos

1. Establish baseline and deployment constraints.
   - Inventory all HTML fragments by page family and location, their styling
     dependencies, source lists, existing links and functional attributes.
   - Preserve all existing staged and unstaged work. Identify a representative
     sample of each page family, language and viewport; record baseline renders
     in a Moodle staging environment if available.
   - Confirm deployed Moodle/Boost Union version and that the relevant HTML
     editors preserve the planned classes and markup. Do not assume repository
     changes automatically update snippets stored in Moodle.
2. Define shared branding and safe SCSS composition.
   - Create shared, namespaced components and two small preset sources for
     visual tokens and any necessary variant-specific component treatments.
     Both presets use the same HTML and `bosonance-` classes.
   - Update `scripts/build-raw-scss.py` to generate two self-contained Raw
     SCSS bundles with explicit, deterministic source order and exactly one
     theme per bundle. The initial outputs were `build/moodle-raw.scss` and
     `build/moodle-raw-free-sample.scss`; the modular structure now generates
     `build/styles/classic-blue.scss` and `build/styles/blue-teal.scss` instead.
     Ensure generated outputs are ignored by Git. Do not rely on relative
     imports resolving inside Moodle's Raw SCSS field.
   -    Consolidate genuinely shared `bsp-` and `ots-` declarations into
   `bosonance-` components, without legacy class aliases, while keeping intentional variants,
     responsive rules and cascade behavior.
   - Harmonize theme navigation, slider and course-card accents with each
     preset. Review broad rules such as `.p-3`, `.border-start`, `.card`,
     `.btn` and `.badge`: preserve existing behavior until staging evidence
     supports narrowing or replacing them; do not make blanket global changes.
3. Migrate HTML families to the shared system.
   - Cover homepage blocks, slider, footer, static and policy pages, login,
     free-sample descriptions and course content, and every online-test-series
     category, description and course-general fragment in all languages.
   - Replace duplicated presentation styles with scoped `bosonance-` classes
     where safe, retain purposeful layout/semantic variants, and ensure either
     preset works with the same migrated snippets. No content rewriting:
     preserve text, links, IDs, classes required by Moodle, ARIA, language
     differences, icons, ordering and interaction semantics.
   - Group highly similar fragments by structure, but verify every file; use
     deterministic transformations and checks when helpful, not blind global
     string replacement.
4. Validate and document.
   - Run focused checks for all source files, exact order and deterministic
     generation of both bundles, distinct preset output, source coverage,
     `bosonance-` custom naming, and
     preservation of HTML content, links, IDs, ARIA and other nonvisual
     attributes. Moodle/Bootstrap selectors are prefix exceptions.
   - On Moodle staging, compile and compare both presets with identical HTML;
     verify class preservation, representative desktop and mobile layouts,
     all exam and language families, hover/focus states, keyboard navigation,
     slider and forms.
     Check contrast against WCAG AA: at least 4.5:1 for normal text and 3:1 for
     large text and relevant non-text controls; include focus indicators.
   - Document source editing, generation, and manual deployment in README.
     To switch presets, paste exactly one generated bundle into Raw SCSS and
     save; HTML does not need to be changed again. Back up existing Moodle
     settings/snippets; coordinate deployment of Raw SCSS and migrated HTML,
     purge caches if needed, and retain a rollback path. Old `bsp-` and `ots-`
     snippets will not be styled by the new bundles.

## Considerations

- The working tree contains existing changes; inspect and preserve them when
  implementing. Generated Raw SCSS bundles are outputs, not the source of
  truth.
- Current `!important` global overrides can affect Moodle UI outside these
  fragments. Any narrowing is a behavior change and requires staging checks.
- Static scanning cannot establish live appearance, editor sanitization,
  theme compilation compatibility, or whether the repository matches the
  deployed Moodle configuration. Report any unavailable live checks explicitly.
- This plan covers repository changes and a manual rollout guide only; it does
  not automate or perform changes on a live Moodle site.

## Implementation status

- Shared components are split into ordered modules under `styles/shared/`.
  Site-wide themes live under `styles/themes/`, with required tokens and
  optional overrides. The builder discovers themes and can build all or one
  with `--theme`; its cascade order remains Boost Union, theme, shared modules.
- The new outputs are `build/styles/classic-blue.scss` and
  `build/styles/blue-teal.scss`. Legacy generated bundles are retained if
  present, but are no longer regenerated. The original free-sample and
  online-test-series SCSS files are retained under `styles/reference/` as
  references, not verified complete backups, and excluded from the bundles.
  Moodle category and course summary rules remain in the Boost Union overrides.
- Primary fragment actions and Moodle Bootstrap primary buttons use the theme
  primary colors; secondary hero links are outlined. Other Moodle control
  variants retain their own styling.
- All 118 HTML fragments have been checked and migrated to the shared naming
  and relevant components. Nonvisual content and attributes were compared
  with the pre-migration files. Some inline styling remains for page-specific
  presentation and accent colors; review it on staging before further removal.
- Local bundle tests and representative browser checks are available. Actual
  Moodle SCSS compilation, editor class preservation, and full responsive,
  accessibility and rollout checks still require a Moodle staging site.
