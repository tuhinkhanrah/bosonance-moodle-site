# Bosonance block web services

This local plugin targets Moodle 5.3.1. It provides two web-service functions,
restricted to Moodle site administrators:

- `local_bosonanceblocks_list_blocks`: list all stored block instances,
  including hidden or private instances. For HTML blocks it also returns the
  stored title, unformatted content, format and content hash. Other block
  types return location metadata only. The listing describes instances and
  default placement, not every rendered page or visibility override.
- `local_bosonanceblocks_update_html`: update one HTML block's content by
  instance ID. It leaves the title, other configuration and existing
  `block_html/content` files unchanged. It saves the new text as HTML and
  records a Moodle event without the content.

The list can contain sensitive information, and an administrator token can
have broad privileges. Use a dedicated service with only these functions,
protect the token, use HTTPS, and do not put tokens in URLs or commit them.
This plugin does not permit a non-administrator account to use either function.

## Installation

1. Back up the Moodle database and data directory, and test on a staging copy.
2. Copy this directory to Moodle's `local/bosonanceblocks` plugin directory
   (on a Moodle 5.3 layout this is under the web root's `local` directory).
   Complete the Moodle upgrade through Site administration or Moodle's CLI.
3. Enable web services and the REST protocol if needed. Create a restricted
   custom service, add the two functions above, and authorize only the
   intended administrator. Give the account permission to use REST and
   generate a token for that service.
4. Verify the functions are shown in the site's Web Services API
   Documentation and test listing and a reversible update on staging.

The course/category description uploader is separate from this plugin.
Use [`scripts/manage-moodle-blocks.py`](../../../scripts/manage-moodle-blocks.py)
in the repository to list blocks or update HTML block content through these web
services. Installing the plugin does not change any block.

## Request contract

The list function takes no parameters. Each item contains `instanceid`,
`blockname`, `contextid`, `parentcontextid`, `pagetypepattern`,
`subpagepattern`, `showinsubcontexts`, `defaultregion`, and `defaultweight`.
For an HTML block, `html` contains `title`, `content`, `format`, and
`contenthash`. The hash is lowercase SHA-256 of the UTF-8 bytes of the stored
content, not of rendered HTML.

The update function requires `instanceid`, `content`, and `expectedhash`.
Use the HTML block's `contenthash` from a fresh list as `expectedhash`. If
the stored content differs, the update fails; list and review it again.
This is a preflight check, **not an atomic concurrency lock** against other
Moodle editors. Avoid editing the same block concurrently.

Existing embedded files are not uploaded or removed. To refer to a file
already in that block's file area, retain its stored `@@PLUGINFILE@@/…`
reference. This plugin does not import files or convert draft-file URLs.

## Tests

After installing the plugin in a Moodle development installation and
initializing Moodle's separate PHPUnit database:

```bash
vendor/bin/phpunit local/bosonanceblocks/tests/external_test.php
```

Run this from the Moodle checkout's test working directory; on installations
with a `public` web root, the test path is
`public/local/bosonanceblocks/tests/external_test.php`.
