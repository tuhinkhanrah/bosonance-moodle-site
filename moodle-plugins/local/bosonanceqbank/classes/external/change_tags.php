<?php
namespace local_bosonanceqbank\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_bosonanceqbank\tag_changer;

class change_tags extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'previewtoken' => new external_value(PARAM_ALPHANUM, 'Token from preview_tags'),
            'exclusive' => new external_value(PARAM_BOOL, 'Confirm exclusive bank and tag access'),
        ]);
    }

    public static function execute(string $previewtoken, bool $exclusive): array {
        $params = self::validate_parameters(self::execute_parameters(), compact('previewtoken', 'exclusive'));
        return tag_changer::apply($params['previewtoken'], (bool)$params['exclusive']);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure(['tagsid' => new external_value(PARAM_INT, 'Tag audit ID')]
            + preview_tags::result_fields());
    }
}
