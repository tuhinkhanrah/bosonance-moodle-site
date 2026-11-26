<?php
namespace local_bosonanceqbank\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_bosonanceqbank\deleter;

class delete_question extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'previewtoken' => new external_value(PARAM_ALPHANUM, 'Token from the matching deletion preview'),
            'exclusive' => new external_value(PARAM_BOOL, 'Confirm no other relevant writers from preview through deletion'),
        ]);
    }

    public static function execute(string $previewtoken, bool $exclusive): array {
        $params = self::validate_parameters(self::execute_parameters(), compact('previewtoken', 'exclusive'));
        return deleter::apply('question', $params['previewtoken'], (bool)$params['exclusive']);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure(preview_delete_question::result_fields() + [
            'deletionid' => new external_value(PARAM_INT, 'Successful deletion audit ID'),
            'deleted' => new external_value(PARAM_BOOL, 'Permanent deletion verified, never a hidden-status fallback'),
        ]);
    }
}
