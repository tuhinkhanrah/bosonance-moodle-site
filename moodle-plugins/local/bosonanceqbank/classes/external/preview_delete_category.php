<?php
namespace local_bosonanceqbank\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_bosonanceqbank\deleter;

class preview_delete_category extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Exact bank module context'),
            'categoryid' => new external_value(PARAM_INT, 'Non-top category selected for deletion'),
            'recursive' => new external_value(PARAM_BOOL, 'Delete the whole subtree only if every category has no entries',
                VALUE_DEFAULT, false),
            'deleteunusedquestions' => new external_value(PARAM_BOOL,
                'Recursively delete the subtree and all question entries only when every question is unused',
                VALUE_DEFAULT, false),
        ]);
    }

    public static function execute(int $contextid, int $categoryid, bool $recursive = false,
            bool $deleteunusedquestions = false): array {
        $params = self::validate_parameters(self::execute_parameters(),
            compact('contextid', 'categoryid', 'recursive', 'deleteunusedquestions'));
        if ($params['recursive'] && $params['deleteunusedquestions']) {
            throw new \invalid_parameter_exception(get_string('deletemodeconflict', 'local_bosonanceqbank'));
        }
        return deleter::preview('category', $params['contextid'], $params['categoryid'], 0,
            (bool)$params['recursive'], (bool)$params['deleteunusedquestions']);
    }

    public static function result_fields(bool $preview): array {
        $categoryfields = [
            'categoryid' => new external_value(PARAM_INT, 'Category to delete'),
            'parentid' => new external_value(PARAM_INT, 'Its parent before deletion'),
        ];
        if ($preview) {
            $categoryfields['name'] = new external_value(PARAM_RAW, 'Display name; not retained in the deletion audit');
        }
        $entryfields = [
            'entryid' => new external_value(PARAM_INT, 'Whole question-bank entry'),
            'categoryid' => new external_value(PARAM_INT, 'Category containing the entry'),
            'questionid' => new external_value(PARAM_INT, 'Latest question version ID'),
            'version' => new external_value(PARAM_INT, 'Latest version number'),
            'qtype' => new external_value(PARAM_PLUGIN, 'Moodle question type'),
            'questioncount' => new external_value(PARAM_INT, 'Number of versions in the whole entry'),
            'versions' => preview_move::versions_schema(),
        ];
        if ($preview) {
            $entryfields['name'] = new external_value(PARAM_RAW, 'Question name for operator review');
        }
        return preview_delete_question::result_fields() + [
            'recursive' => new external_value(PARAM_BOOL, 'Whole subtree deletion was requested', VALUE_OPTIONAL),
            'categorycount' => new external_value(PARAM_INT, 'Number of categories to delete, including the selected root',
                VALUE_OPTIONAL),
            'categories' => new external_multiple_structure(new external_single_structure($categoryfields),
                'Complete recursive deletion scope', VALUE_OPTIONAL),
            'deleteunusedquestions' => new external_value(PARAM_BOOL,
                'The preview includes eligible question entries for deletion', VALUE_OPTIONAL),
            'entrycount' => new external_value(PARAM_INT, 'Number of whole question-bank entries to delete',
                VALUE_OPTIONAL),
            'questionentries' => new external_multiple_structure(new external_single_structure($entryfields),
                'Every question entry/version included in the category deletion', VALUE_OPTIONAL),
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure(self::result_fields(true) + [
            'name' => new external_value(PARAM_RAW, 'Selected category name'),
            'previewtoken' => new external_value(PARAM_ALPHANUM, 'Secret single-use deletion token'),
            'expires' => new external_value(PARAM_INT, 'Unix time when the token expires'),
        ]);
    }
}
