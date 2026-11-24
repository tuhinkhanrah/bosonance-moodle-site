<?php
namespace local_bosonanceqbank\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_single_structure;
use core_external\external_value;

class access extends external_api {
    public static function bank(int $contextid): \context {
        global $CFG;

        if (!is_siteadmin()) {
            throw new \moodle_exception('adminrequired', 'local_bosonanceqbank');
        }
        $context = \context::instance_by_id($contextid, MUST_EXIST);
        self::validate_context($context);
        if ($context->contextlevel !== CONTEXT_MODULE) {
            throw new \moodle_exception('invalidbank', 'local_bosonanceqbank');
        }
        $cm = get_coursemodule_from_id(null, $context->instanceid, 0, false, MUST_EXIST);
        if (!in_array($cm->modname, ['qbank', 'quiz'], true)) {
            throw new \moodle_exception('invalidbank', 'local_bosonanceqbank');
        }
        require_capability('moodle/question:viewall', $context);
        require_once($CFG->libdir . '/questionlib.php');
        return $context;
    }

    public static function category(int $contextid, int $categoryid): \stdClass {
        global $DB;

        $category = $DB->get_record('question_categories', ['id' => $categoryid], '*', MUST_EXIST);
        if ((int)$category->contextid !== $contextid) {
            throw new \moodle_exception('wrongcontext', 'local_bosonanceqbank');
        }
        return $category;
    }

    public static function question(int $contextid, int $questionid): \stdClass {
        global $DB;

        $question = $DB->get_record_sql(
            'SELECT q.*, qv.version, qv.status, qv.questionbankentryid,
                    qbe.questioncategoryid AS categoryid
               FROM {question} q
               JOIN {question_versions} qv ON qv.questionid = q.id
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
              WHERE q.id = :id',
            ['id' => $questionid], MUST_EXIST
        );
        self::category($contextid, (int)$question->categoryid);
        question_require_capability_on($questionid, 'view');
        return $question;
    }

    public static function page(int $offset, int $limit): void {
        if ($offset < 0 || $limit < 1 || $limit > 100) {
            throw new \invalid_parameter_exception(get_string('invalidpage', 'local_bosonanceqbank'));
        }
    }

    public static function summary(\stdClass $question): array {
        return [
            'questionid' => (int)$question->id,
            'entryid' => (int)$question->questionbankentryid,
            'categoryid' => (int)$question->categoryid,
            'version' => (int)$question->version,
            'status' => $question->status,
            'name' => $question->name,
            'qtype' => $question->qtype,
            'timemodified' => (int)$question->timemodified,
        ];
    }

    public static function summary_schema(): external_single_structure {
        return new external_single_structure([
            'questionid' => new external_value(PARAM_INT, 'Version-specific question ID'),
            'entryid' => new external_value(PARAM_INT, 'Stable bank entry ID'),
            'categoryid' => new external_value(PARAM_INT, 'Question category ID'),
            'version' => new external_value(PARAM_INT, 'Version number'),
            'status' => new external_value(PARAM_ALPHA, 'Version status'),
            'name' => new external_value(PARAM_RAW, 'Stored question name'),
            'qtype' => new external_value(PARAM_PLUGIN, 'Question type'),
            'timemodified' => new external_value(PARAM_INT, 'Last modification time'),
        ]);
    }
}
