<?php
namespace local_bosonanceqbank\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_bosonanceqbank\deleter;

class delete_category extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return delete_question::execute_parameters();
    }

    public static function execute(string $previewtoken, bool $exclusive): array {
        $params = self::validate_parameters(self::execute_parameters(), compact('previewtoken', 'exclusive'));
        return deleter::apply('category', $params['previewtoken'], (bool)$params['exclusive']);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure(preview_delete_category::result_fields(false) + [
            'deletionid' => new external_value(PARAM_INT, 'Successful deletion audit ID'),
            'deleted' => new external_value(PARAM_BOOL, 'Deletion of every previewed category/question scope was verified'),
        ]);
    }
}
