<?php
namespace local_bosonanceqbank\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_bosonanceqbank\tag_changer;

class preview_tags extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Question bank module context'),
            'questionid' => new external_value(PARAM_INT, 'Latest question version ID'),
            'expectedversion' => new external_value(PARAM_INT, 'Expected latest version number'),
            'tagids' => new external_multiple_structure(new external_value(PARAM_INT, 'Existing standard tag ID')),
        ]);
    }

    public static function execute(int $contextid, int $questionid, int $expectedversion, array $tagids): array {
        $params = self::validate_parameters(self::execute_parameters(),
            compact('contextid', 'questionid', 'expectedversion', 'tagids'));
        return tag_changer::preview($params['contextid'], $params['questionid'], $params['expectedversion'], $params['tagids']);
    }

    public static function result_fields(): array {
        $tagfields = fn() => new external_multiple_structure(new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Standard tag ID'),
            'name' => new external_value(PARAM_RAW, 'Tag display name'),
        ]));
        return [
            'contextid' => new external_value(PARAM_INT, 'Bank context'),
            'categoryid' => new external_value(PARAM_INT, 'Question category'),
            'entryid' => new external_value(PARAM_INT, 'Question bank entry'),
            'questionid' => new external_value(PARAM_INT, 'Latest version ID'),
            'version' => new external_value(PARAM_INT, 'Version number'),
            'oldtags' => $tagfields(),
            'newtags' => $tagfields(),
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure(self::result_fields() + [
            'previewtoken' => new external_value(PARAM_ALPHANUM, 'Single-use tag confirmation token'),
            'expires' => new external_value(PARAM_INT, 'Token expiry time'),
        ]);
    }
}
