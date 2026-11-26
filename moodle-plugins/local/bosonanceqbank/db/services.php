<?php
defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_bosonanceqbank_preview_delete_question' => [
        'classname' => 'local_bosonanceqbank\external\preview_delete_question',
        'description' => 'Preview irreversible deletion of every version of an unused question entry.',
        'type' => 'write',
    ],
    'local_bosonanceqbank_delete_question' => [
        'classname' => 'local_bosonanceqbank\external\delete_question',
        'description' => 'Permanently delete an unused entry after single-use preview confirmation.',
        'type' => 'write',
    ],
    'local_bosonanceqbank_preview_delete_category' => [
        'classname' => 'local_bosonanceqbank\external\preview_delete_category',
        'description' => 'Preview irreversible deletion of an unused whole question subtree/category scope.',
        'type' => 'write',
    ],
    'local_bosonanceqbank_delete_category' => [
        'classname' => 'local_bosonanceqbank\external\delete_category',
        'description' => 'Permanently delete the exact category/question scope after single-use preview confirmation.',
        'type' => 'write',
    ],
    'local_bosonanceqbank_list_categories' => [
        'classname' => 'local_bosonanceqbank\external\list_categories',
        'description' => 'List categories in an explicitly authorized question bank.',
        'type' => 'read',
    ],
    'local_bosonanceqbank_list_questions' => [
        'classname' => 'local_bosonanceqbank\external\list_questions',
        'description' => 'List latest question versions in one category.',
        'type' => 'read',
    ],
    'local_bosonanceqbank_get_question' => [
        'classname' => 'local_bosonanceqbank\external\get_question',
        'description' => 'Read question metadata, versions and usage flags.',
        'type' => 'read',
    ],
    'local_bosonanceqbank_export_xml' => [
        'classname' => 'local_bosonanceqbank\external\export_xml',
        'description' => 'Export explicitly selected question versions as Moodle XML.',
        'type' => 'read',
    ],
    'local_bosonanceqbank_preview_xml' => [
        'classname' => 'local_bosonanceqbank\external\preview_xml',
        'description' => 'Validate an owned XML upload, warn about duplicates and issue a single-use import token.',
        'type' => 'write',
    ],
    'local_bosonanceqbank_import_xml' => [
        'classname' => 'local_bosonanceqbank\external\import_xml',
        'description' => 'Additively import a previewed XML file after warning acknowledgement.',
        'type' => 'write',
    ],
    'local_bosonanceqbank_preview_move' => [
        'classname' => 'local_bosonanceqbank\external\preview_move',
        'description' => 'Preview a single question entry move within one bank.',
        'type' => 'write',
    ],
    'local_bosonanceqbank_move_question' => [
        'classname' => 'local_bosonanceqbank\external\move_question',
        'description' => 'Apply a confirmed, single-use question move preview.',
        'type' => 'write',
    ],
    'local_bosonanceqbank_preview_status' => [
        'classname' => 'local_bosonanceqbank\external\preview_status',
        'description' => 'Preview a change to the status of one latest question version.',
        'type' => 'write',
    ],
    'local_bosonanceqbank_change_status' => [
        'classname' => 'local_bosonanceqbank\external\change_status',
        'description' => 'Apply a confirmed, single-use status preview.',
        'type' => 'write',
    ],
    'local_bosonanceqbank_preview_tags' => [
        'classname' => 'local_bosonanceqbank\external\preview_tags',
        'description' => 'Preview standard tag assignments for one latest question version.',
        'type' => 'write',
    ],
    'local_bosonanceqbank_change_tags' => [
        'classname' => 'local_bosonanceqbank\external\change_tags',
        'description' => 'Apply a confirmed, single-use tag assignment preview.',
        'type' => 'write',
    ],
    'local_bosonanceqbank_preview_category' => [
        'classname' => 'local_bosonanceqbank\external\preview_category',
        'description' => 'Preview same-bank category creation or empty-leaf renaming.',
        'type' => 'write',
    ],
    'local_bosonanceqbank_change_category' => [
        'classname' => 'local_bosonanceqbank\external\change_category',
        'description' => 'Apply a confirmed, single-use category preview.',
        'type' => 'write',
    ],
];
