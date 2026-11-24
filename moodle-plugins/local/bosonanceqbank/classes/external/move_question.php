<?php
namespace local_bosonanceqbank\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_bosonanceqbank\mover;

class move_question extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'previewtoken' => new external_value(PARAM_ALPHANUM, 'Token from preview_move'),
            'exclusive' => new external_value(PARAM_BOOL, 'Confirm no other bank writes are running'),
        ]);
    }

    public static function execute(string $previewtoken, bool $exclusive): array {
        $params = self::validate_parameters(self::execute_parameters(), compact('previewtoken', 'exclusive'));
        return mover::apply($params['previewtoken'], (bool)$params['exclusive']);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'moveid' => new external_value(PARAM_INT, 'Move audit record ID'),
            'contextid' => new external_value(PARAM_INT, 'Bank context'),
            'sourceid' => new external_value(PARAM_INT, 'Previous category'),
            'targetid' => new external_value(PARAM_INT, 'New category'),
            'entryid' => new external_value(PARAM_INT, 'Moved bank entry'),
            'questionid' => new external_value(PARAM_INT, 'Latest question ID'),
            'version' => new external_value(PARAM_INT, 'Expected latest version'),
            'versions' => preview_move::versions_schema(),
        ]);
    }
}
