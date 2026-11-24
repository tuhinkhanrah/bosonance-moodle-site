<?php
namespace local_bosonanceqbank\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_bosonanceqbank\status_changer;

class preview_status extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Question bank module context'),
            'questionid' => new external_value(PARAM_INT, 'Latest question version ID'),
            'expectedversion' => new external_value(PARAM_INT, 'Expected latest version number'),
            'expectedstatus' => new external_value(PARAM_ALPHA, 'Expected current status'),
            'targetstatus' => new external_value(PARAM_ALPHA, 'Requested status'),
        ]);
    }

    public static function execute(int $contextid, int $questionid, int $expectedversion,
            string $expectedstatus, string $targetstatus): array {
        $params = self::validate_parameters(self::execute_parameters(),
            compact('contextid', 'questionid', 'expectedversion', 'expectedstatus', 'targetstatus'));
        return status_changer::preview($params['contextid'], $params['questionid'], $params['expectedversion'],
            $params['expectedstatus'], $params['targetstatus']);
    }

    public static function result_fields(): array {
        return [
            'contextid' => new external_value(PARAM_INT, 'Bank context'),
            'categoryid' => new external_value(PARAM_INT, 'Question category'),
            'entryid' => new external_value(PARAM_INT, 'Question bank entry'),
            'questionid' => new external_value(PARAM_INT, 'Latest question version ID'),
            'version' => new external_value(PARAM_INT, 'Version number'),
            'oldstatus' => new external_value(PARAM_ALPHA, 'Current status'),
            'newstatus' => new external_value(PARAM_ALPHA, 'Requested status'),
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure(self::result_fields() + [
            'previewtoken' => new external_value(PARAM_ALPHANUM, 'Single-use status confirmation token'),
            'expires' => new external_value(PARAM_INT, 'Token expiry time'),
        ]);
    }
}
