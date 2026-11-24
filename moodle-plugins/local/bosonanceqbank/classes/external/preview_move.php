<?php
namespace local_bosonanceqbank\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_bosonanceqbank\mover;

class preview_move extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Question bank module context'),
            'questionid' => new external_value(PARAM_INT, 'Latest question version ID'),
            'expectedversion' => new external_value(PARAM_INT, 'Expected latest version number'),
            'targetid' => new external_value(PARAM_INT, 'Destination category in the same bank'),
        ]);
    }

    public static function execute(int $contextid, int $questionid, int $expectedversion, int $targetid): array {
        $params = self::validate_parameters(self::execute_parameters(),
            compact('contextid', 'questionid', 'expectedversion', 'targetid'));
        return mover::preview($params['contextid'], $params['questionid'], $params['expectedversion'], $params['targetid']);
    }

    public static function versions_schema(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure([
            'questionid' => new external_value(PARAM_INT, 'Version-specific question ID'),
            'version' => new external_value(PARAM_INT, 'Version number'),
            'status' => new external_value(PARAM_ALPHA, 'Version status'),
            'timemodified' => new external_value(PARAM_INT, 'Question modification time'),
        ]));
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'contextid' => new external_value(PARAM_INT, 'Bank context'),
            'sourceid' => new external_value(PARAM_INT, 'Source category'),
            'targetid' => new external_value(PARAM_INT, 'Destination category'),
            'entryid' => new external_value(PARAM_INT, 'Bank entry whose every version will move'),
            'questionid' => new external_value(PARAM_INT, 'Latest question ID'),
            'version' => new external_value(PARAM_INT, 'Expected latest version'),
            'versions' => self::versions_schema(),
            'previewtoken' => new external_value(PARAM_ALPHANUM, 'Single-use move confirmation token'),
            'expires' => new external_value(PARAM_INT, 'Token expiry time'),
        ]);
    }
}
