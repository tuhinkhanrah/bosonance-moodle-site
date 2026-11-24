<?php
defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_bosonanceblocks_list_blocks' => [
        'classname' => 'local_bosonanceblocks\external\list_blocks',
        'description' => 'List block instances across the site.',
        'type' => 'read',
    ],
    'local_bosonanceblocks_update_html' => [
        'classname' => 'local_bosonanceblocks\external\update_html',
        'description' => 'Update the content of an HTML block instance.',
        'type' => 'write',
    ],
];
