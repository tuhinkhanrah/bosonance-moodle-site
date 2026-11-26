<?php
namespace local_bosonanceqbank\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_bosonanceqbank\importer;

class preview_xml extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Explicit destination bank module context ID'),
            'categoryid' => new external_value(PARAM_INT, 'Allowed subtree root; unclassified records go here'),
            'draftitemid' => new external_value(PARAM_INT, 'Current user draft item ID'),
            'filename' => new external_value(PARAM_FILE, 'Filename in the root of this draft area'),
        ]);
    }

    public static function execute(int $contextid, int $categoryid, int $draftitemid, string $filename): array {
        $params = self::validate_parameters(self::execute_parameters(), compact('contextid', 'categoryid', 'draftitemid', 'filename'));
        if ($params['filename'] !== $filename) {
            throw new \invalid_parameter_exception(get_string('invalidfile', 'local_bosonanceqbank'));
        }
        return importer::preview($contextid, $categoryid, $draftitemid, $filename);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'contextid' => new external_value(PARAM_INT, 'Explicit bank context ID'),
            'categoryid' => new external_value(PARAM_INT, 'Allowed subtree root ID'),
            'sha256' => new external_value(PARAM_ALPHANUM, 'SHA-256 of uploaded XML bytes'),
            'questioncount' => new external_value(PARAM_INT, 'Questions that would be imported'),
            'categories' => new external_multiple_structure(new external_single_structure([
                'path' => new external_value(PARAM_RAW, 'Validated module-relative XML path'),
                'categoryid' => new external_value(PARAM_INT, 'Existing category ID or zero if missing'),
                'action' => new external_value(PARAM_ALPHA, 'reuse or create; nothing is changed by preview'),
            ])),
            'tags' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Existing tag ID, or zero for a tag created on apply'),
                'name' => new external_value(PARAM_RAW, 'Existing or planned tag display name'),
                'isstandard' => new external_value(PARAM_BOOL, 'Whether the tag is standard; new tags are nonstandard'),
                'collectionid' => new external_value(PARAM_INT, 'Question tag collection ID'),
                'action' => new external_value(PARAM_ALPHA, 'reuse or create; preview does not create tags'),
            ])),
            'warnings' => new external_multiple_structure(new external_single_structure([
                'code' => new external_value(PARAM_ALPHANUMEXT,
                    'exact_repeat, file_imported_elsewhere, duplicate_in_file or possible_existing_duplicate'),
                'index' => new external_value(PARAM_INT, 'One-based question index, zero for file-level warning'),
                'matchcount' => new external_value(PARAM_INT, 'Matching previous imports or question versions'),
                'questionids' => new external_multiple_structure(new external_value(PARAM_INT, 'Up to 20 matching question IDs')),
            ])),
            'warningshash' => new external_value(PARAM_ALPHANUM, 'Pass to import_xml to acknowledge these warnings'),
            'previewtoken' => new external_value(PARAM_ALPHANUM, 'Secret single-use token for import_xml'),
            'expires' => new external_value(PARAM_INT, 'Unix time when the token expires'),
            'canapply' => new external_value(PARAM_BOOL, 'Validation passed; import_xml may be called'),
            'requiresduplicateoverride' => new external_value(PARAM_BOOL,
                'Exact repeat of an earlier import; import_xml needs allowduplicate=1'),
        ]);
    }
}
