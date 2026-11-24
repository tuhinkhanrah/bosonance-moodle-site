<?php
namespace local_bosonanceqbank\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_bosonanceqbank\status_changer;

class change_status extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'previewtoken' => new external_value(PARAM_ALPHANUM, 'Token from preview_status'),
            'exclusive' => new external_value(PARAM_BOOL, 'Confirm no other bank writes are running'),
        ]);
    }

    public static function execute(string $previewtoken, bool $exclusive): array {
        $params = self::validate_parameters(self::execute_parameters(), compact('previewtoken', 'exclusive'));
        return status_changer::apply($params['previewtoken'], (bool)$params['exclusive']);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure(['statusid' => new external_value(PARAM_INT, 'Status audit ID')]
            + preview_status::result_fields());
    }
}
