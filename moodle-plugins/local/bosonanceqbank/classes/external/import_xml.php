<?php
namespace local_bosonanceqbank\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_bosonanceqbank\importer;

class import_xml extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'previewtoken' => new external_value(PARAM_ALPHANUM, 'Token returned by preview_xml'),
            'warningshash' => new external_value(PARAM_ALPHANUM, 'warningshash returned by the same preview'),
            'allowduplicate' => new external_value(PARAM_BOOL, 'Required to repeat an exact earlier import', VALUE_DEFAULT, false),
        ]);
    }

    public static function execute(string $previewtoken, string $warningshash, bool $allowduplicate = false): array {
        $params = self::validate_parameters(self::execute_parameters(), compact('previewtoken', 'warningshash', 'allowduplicate'));
        return importer::apply($params['previewtoken'], $params['warningshash'], (bool)$params['allowduplicate']);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'importid' => new external_value(PARAM_INT, 'Import record ID'),
            'contextid' => new external_value(PARAM_INT, 'Bank context ID'),
            'categoryid' => new external_value(PARAM_INT, 'Destination subtree root ID'),
            'sha256' => new external_value(PARAM_ALPHANUM, 'SHA-256 of imported XML bytes'),
            'questioncount' => new external_value(PARAM_INT, 'Questions created'),
            'duplicateoverride' => new external_value(PARAM_BOOL, 'Whether an exact repeat was explicitly allowed'),
            'categories' => new external_multiple_structure(new external_single_structure([
                'path' => new external_value(PARAM_RAW, 'XML category path'),
                'categoryid' => new external_value(PARAM_INT, 'Category ID after import'),
                'action' => new external_value(PARAM_ALPHA, 'reuse or create'),
            ])),
            'questions' => new external_multiple_structure(new external_single_structure([
                'index' => new external_value(PARAM_INT, 'One-based question index in the file'),
                'questionid' => new external_value(PARAM_INT, 'Created question ID'),
                'categoryid' => new external_value(PARAM_INT, 'Destination category ID'),
                'name' => new external_value(PARAM_TEXT, 'Question name'),
                'qtype' => new external_value(PARAM_PLUGIN, 'Question type'),
            ])),
        ]);
    }
}
