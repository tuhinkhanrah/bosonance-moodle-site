<?php
namespace local_bosonanceqbank\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

class get_question extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Bank module context ID'),
            'questionid' => new external_value(PARAM_INT, 'Version-specific question ID'),
            'includecontent' => new external_value(PARAM_BOOL, 'Include stored text and general feedback', VALUE_DEFAULT, false),
            'offset' => new external_value(PARAM_INT, 'Version history offset', VALUE_DEFAULT, 0),
            'limit' => new external_value(PARAM_INT, 'Version history page size', VALUE_DEFAULT, 50),
        ]);
    }

    public static function execute(
        int $contextid, int $questionid, bool $includecontent = false, int $offset = 0, int $limit = 50
    ): array {
        global $DB;

        self::validate_parameters(self::execute_parameters(), compact('contextid', 'questionid', 'includecontent', 'offset', 'limit'));
        access::bank($contextid);
        access::page($offset, $limit);
        $question = access::question($contextid, $questionid);
        $versions = $DB->get_records('question_versions', ['questionbankentryid' => $question->questionbankentryid],
            'version DESC', '*', $offset, $limit);
        $history = [];
        foreach ($versions as $version) {
            $history[] = ['questionid' => (int)$version->questionid, 'version' => (int)$version->version, 'status' => $version->status];
        }
        $result = [
            'question' => access::summary($question),
            'inuse' => questions_in_use([$questionid]),
            'entryinuse' => questions_in_use(array_values($DB->get_fieldset_select(
                'question_versions', 'questionid', 'questionbankentryid = :entryid',
                ['entryid' => $question->questionbankentryid]
            ))),
            'versiontotal' => $DB->count_records('question_versions', ['questionbankentryid' => $question->questionbankentryid]),
            'versions' => $history,
        ];
        if ($includecontent) {
            $result['content'] = [
                'questiontext' => $question->questiontext,
                'questiontextformat' => (int)$question->questiontextformat,
                'generalfeedback' => $question->generalfeedback,
                'generalfeedbackformat' => (int)$question->generalfeedbackformat,
            ];
        }
        return $result;
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'question' => access::summary_schema(),
            'inuse' => new external_value(PARAM_BOOL, 'Moodle usage check for selected version; not authorization to delete'),
            'entryinuse' => new external_value(PARAM_BOOL, 'Moodle usage check across all entry versions'),
            'versiontotal' => new external_value(PARAM_INT, 'Total versions'),
            'versions' => new external_multiple_structure(new external_single_structure([
                'questionid' => new external_value(PARAM_INT, 'Version-specific ID'),
                'version' => new external_value(PARAM_INT, 'Version number'),
                'status' => new external_value(PARAM_ALPHA, 'Version status'),
            ])),
            'content' => new external_single_structure([
                'questiontext' => new external_value(PARAM_RAW, 'Stored text'),
                'questiontextformat' => new external_value(PARAM_INT, 'Stored format'),
                'generalfeedback' => new external_value(PARAM_RAW, 'Stored general feedback'),
                'generalfeedbackformat' => new external_value(PARAM_INT, 'Stored format'),
            ], 'Content only when requested; answers are available via export', VALUE_OPTIONAL),
        ]);
    }
}
