# bosonance-moodle-site

## Generate Boost Union Raw SCSS

From the repository root, run:

```bash
python scripts/build_raw_scss.py
```

Copy the entire generated `build/moodle-raw.scss` into Boost Union's Raw SCSS
setting, replacing its previous contents, and save the setting. The generated
file is ignored by Git; edit the source files instead and regenerate it after
changes. Check the result on a test site before applying it to production.

The script concatenates the free sample pack, online test series, and Boost
Union SCSS in that order. The Boost Union file stays last so its shared
borderless overrides retain their intended position in the cascade. To include
another CSS or SCSS file, add its repository-relative path to the script's
`SOURCES` list in the desired order. It does not compile SCSS or resolve
relative imports; Moodle compiles the pasted result.