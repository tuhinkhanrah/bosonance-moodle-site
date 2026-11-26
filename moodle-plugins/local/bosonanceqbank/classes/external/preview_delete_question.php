<?php
namespace local_bosonanceqbank\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_bosonanceqbank\deleter;

class preview_delete_question extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Exact bank module context'),
            'questionid' => new external_value(PARAM_INT, 'Latest question version ID'),
            'expectedversion' => new external_value(PARAM_INT, 'Expected latest version number'),
        ]);
    }

    public static function execute(int $contextid, int $questionid, int $expectedversion): array {
        $params = self::validate_parameters(self::execute_parameters(), compact('contextid', 'questionid', 'expectedversion'));
        return deleter::preview('question', $params['contextid'], $params['questionid'], $params['expectedversion']);
    }

    public static function result_fields(): array {
        return [
            'kind' => new external_value(PARAM_ALPHA, 'question or category'),
            'contextid' => new external_value(PARAM_INT, 'Bank context ID'),
            'categoryid' => new external_value(PARAM_INT, 'Containing or deleted category'),
            'parentid' => new external_value(PARAM_INT, 'Category parent'),
            'entryid' => new external_value(PARAM_INT, 'Whole entry to delete, zero for category'),
            'questionid' => new external_value(PARAM_INT, 'Selected latest question, zero for category'),
            'version' => new external_value(PARAM_INT, 'Latest version number, zero for category'),
            'questioncount' => new external_value(PARAM_INT, 'Question versions in the requested deletion scope'),
            'versions' => preview_move::versions_schema(),
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure(self::result_fields() + [
            'name' => new external_value(PARAM_RAW, 'Display name for confirmation; not retained in deletion audit'),
            'previewtoken' => new external_value(PARAM_ALPHANUM, 'Secret single-use deletion token'),
            'expires' => new external_value(PARAM_INT, 'Unix expiry time'),
        ]);
    }
}
