<?php
namespace local_bosonanceqbank\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_bosonanceqbank\category_changer;

class preview_category extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Question bank module context'),
            'categoryid' => new external_value(PARAM_INT, 'Existing parent for create, or empty leaf for rename'),
            'action' => new external_value(PARAM_ALPHA, 'create or rename'),
            'name' => new external_value(PARAM_RAW, 'Requested plain-text category name'),
        ]);
    }

    public static function execute(int $contextid, int $categoryid, string $action, string $name): array {
        $params = self::validate_parameters(self::execute_parameters(), compact('contextid', 'categoryid', 'action', 'name'));
        return category_changer::preview($params['contextid'], $params['categoryid'], $params['action'], $params['name']);
    }

    public static function result_fields(): array {
        return [
            'contextid' => new external_value(PARAM_INT, 'Bank context'),
            'categoryid' => new external_value(PARAM_INT, 'Selected existing category'),
            'parentid' => new external_value(PARAM_INT, 'Parent of the resulting category'),
            'action' => new external_value(PARAM_ALPHA, 'create or rename'),
            'oldname' => new external_value(PARAM_RAW, 'Previous name; empty for create'),
            'newname' => new external_value(PARAM_RAW, 'Requested category name'),
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure(self::result_fields() + [
            'previewtoken' => new external_value(PARAM_ALPHANUM, 'Single-use category confirmation token'),
            'expires' => new external_value(PARAM_INT, 'Token expiry time'),
        ]);
    }
}
