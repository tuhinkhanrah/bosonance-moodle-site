<?php
namespace local_bosonanceqbank;

defined('MOODLE_INTERNAL') || die();

use local_bosonanceqbank\external\access;

/**
 * Preview-bound moves of one unused entry within a single question bank.
 */
class mover {
    private const TABLE = 'local_bosonanceqbank_move';
    private const TTL = 1800;

    public static function preview(int $contextid, int $questionid, int $expectedversion, int $targetid): array {
        global $DB, $USER;

        self::enabled();
        $context = access::bank($contextid);
        $analysis = self::analyse($context, $questionid, $expectedversion, $targetid);
        $token = bin2hex(random_bytes(32));
        $now = time();
        $DB->delete_records_select(self::TABLE, 'timeused = 0 AND timeexpires < ?', [$now]);
        $DB->insert_record(self::TABLE, (object)[
            'userid' => $USER->id, 'contextid' => $contextid, 'sourceid' => $analysis['sourceid'],
            'targetid' => $targetid, 'entryid' => $analysis['entryid'], 'questionid' => $questionid,
            'version' => $expectedversion, 'tokenhash' => hash('sha256', $token),
            'statehash' => self::state_hash($analysis), 'versions' => json_encode($analysis['versions'], JSON_THROW_ON_ERROR),
            'timecreated' => $now, 'timeexpires' => $now + self::TTL, 'timeused' => 0,
        ]);
        return [
            'contextid' => $contextid, 'sourceid' => $analysis['sourceid'], 'targetid' => $targetid,
            'entryid' => $analysis['entryid'], 'questionid' => $questionid, 'version' => $expectedversion,
            'versions' => $analysis['versions'], 'previewtoken' => $token, 'expires' => $now + self::TTL,
        ];
    }

    public static function apply(string $token, bool $exclusive): array {
        global $DB, $USER;

        self::enabled();
        if (!preg_match('/^[0-9a-f]{64}$/', $token) || !$exclusive) {
            throw new \invalid_parameter_exception(get_string('moveconfirmation', 'local_bosonanceqbank'));
        }
        $DB->transactions_forbidden();
        if (!in_array($DB->get_dbfamily(), ['mysql', 'postgres'], true)) {
            throw new \moodle_exception('movedatabase', 'local_bosonanceqbank');
        }
        $record = $DB->get_record(self::TABLE, ['tokenhash' => hash('sha256', $token)]);
        if (!$record || (int)$record->userid !== (int)$USER->id) {
            throw new \moodle_exception('previewinvalid', 'local_bosonanceqbank');
        }
        $context = access::bank((int)$record->contextid);
        $lock = \core\lock\lock_config::get_lock_factory('local_bosonanceqbank_import')
            ->get_lock('context_' . $context->id, 30);
        if (!$lock) {
            throw new \moodle_exception('importbusy', 'local_bosonanceqbank');
        }
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                $record = $DB->get_record_sql('SELECT * FROM {' . self::TABLE . '} WHERE id = ? FOR UPDATE',
                    [$record->id], MUST_EXIST);
                if ((int)$record->userid !== (int)$USER->id) {
                    throw new \moodle_exception('previewinvalid', 'local_bosonanceqbank');
                }
                if ($record->timeused) {
                    throw new \moodle_exception('previewused', 'local_bosonanceqbank');
                }
                if ($record->timeexpires < time()) {
                    throw new \moodle_exception('previewexpired', 'local_bosonanceqbank');
                }
                // Lock category and entry rows before re-reading the previewed state.
                $categoryids = [(int)$record->sourceid, (int)$record->targetid];
                sort($categoryids);
                foreach ($categoryids as $categoryid) {
                    $DB->get_record_sql('SELECT id FROM {question_categories} WHERE id = ? FOR UPDATE',
                        [$categoryid], MUST_EXIST);
                }
                $DB->get_record_sql('SELECT id FROM {question_bank_entries} WHERE id = ? FOR UPDATE',
                    [$record->entryid], MUST_EXIST);
                $DB->get_records_sql('SELECT id FROM {question_versions} WHERE questionbankentryid = ? FOR UPDATE',
                    [$record->entryid]);
                $analysis = self::analyse($context, (int)$record->questionid, (int)$record->version, (int)$record->targetid);
                if ($analysis['entryid'] !== (int)$record->entryid || $analysis['sourceid'] !== (int)$record->sourceid
                        || !hash_equals($record->statehash, self::state_hash($analysis))) {
                    throw new \moodle_exception('previewstale', 'local_bosonanceqbank');
                }
                $target = access::category((int)$record->contextid, (int)$record->targetid);
                \qbank_bulkmove\helper::bulk_move_questions((string)$record->questionid, $target);
                $entry = $DB->get_record('question_bank_entries', ['id' => $record->entryid], '*', MUST_EXIST);
                if ((int)$entry->questioncategoryid !== (int)$record->targetid
                        || $entry->idnumber !== $analysis['idnumber']) {
                    throw new \moodle_exception('movefailed', 'local_bosonanceqbank');
                }
                foreach ($analysis['versions'] as $version) {
                    \question_bank::notify_question_edited($version['questionid']);
                }
                $now = time();
                $DB->set_field(self::TABLE, 'timeused', $now, ['id' => $record->id]);
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                if ($DB->is_transaction_started()) {
                    $DB->force_transaction_rollback();
                }
                throw $e;
            }
        } finally {
            $lock->release();
        }
        return [
            'moveid' => (int)$record->id, 'contextid' => (int)$record->contextid,
            'sourceid' => (int)$record->sourceid, 'targetid' => (int)$record->targetid,
            'entryid' => (int)$record->entryid, 'questionid' => (int)$record->questionid,
            'version' => (int)$record->version, 'versions' => $analysis['versions'],
        ];
    }

    private static function enabled(): void {
        global $CFG;

        if (empty($CFG->local_bosonanceqbank_enablemoves)) {
            throw new \moodle_exception('movedisabled', 'local_bosonanceqbank');
        }
        \core_question\local\bank\helper::require_plugin_enabled('qbank_bulkmove');
    }

    private static function analyse(\context $context, int $questionid, int $expectedversion, int $targetid): array {
        global $DB;

        if ($questionid < 1 || $expectedversion < 1 || $targetid < 1) {
            throw new \invalid_parameter_exception(get_string('invalidselection', 'local_bosonanceqbank'));
        }
        require_capability('moodle/question:add', $context);
        $question = access::question((int)$context->id, $questionid);
        question_require_capability_on($questionid, 'move');
        $source = access::category((int)$context->id, (int)$question->categoryid);
        $target = access::category((int)$context->id, $targetid);
        if ((int)$source->id === $targetid || !$target->parent) {
            throw new \moodle_exception('movetarget', 'local_bosonanceqbank');
        }
        $entry = $DB->get_record('question_bank_entries', ['id' => $question->questionbankentryid], '*', MUST_EXIST);
        $rows = $DB->get_records_sql(
            'SELECT qv.id, qv.questionid, qv.version, qv.status, q.parent, q.timemodified
               FROM {question_versions} qv JOIN {question} q ON q.id = qv.questionid
              WHERE qv.questionbankentryid = :entryid ORDER BY qv.version ASC',
            ['entryid' => $entry->id]);
        $versions = [];
        $questionids = [];
        foreach ($rows as $row) {
            if ($row->parent) {
                throw new \moodle_exception('movechildren', 'local_bosonanceqbank');
            }
            $questionids[] = (int)$row->questionid;
            $versions[] = [
                'questionid' => (int)$row->questionid, 'version' => (int)$row->version,
                'status' => $row->status, 'timemodified' => (int)$row->timemodified,
            ];
        }
        $latest = end($versions);
        if (!$latest || $latest['questionid'] !== $questionid || $latest['version'] !== $expectedversion) {
            throw new \moodle_exception('previewstale', 'local_bosonanceqbank');
        }
        [$in, $params] = $DB->get_in_or_equal($questionids);
        if ($DB->record_exists_select('question', "parent $in", $params)) {
            throw new \moodle_exception('movechildren', 'local_bosonanceqbank');
        }
        if ($DB->record_exists_select('tag_instance',
                "component = ? AND itemtype = ? AND itemid $in AND (contextid IS NULL OR contextid <> ?)",
                array_merge(['core_question', 'question'], $params, [$context->id]))) {
            throw new \moodle_exception('movetags', 'local_bosonanceqbank');
        }
        if (questions_in_use($questionids)
                || $DB->record_exists('question_set_references', ['questionscontextid' => $context->id])) {
            throw new \moodle_exception('moveinuse', 'local_bosonanceqbank');
        }
        [$collision] = idnumber_exist_in_question_category($entry->idnumber, $targetid);
        if ($collision) {
            throw new \moodle_exception('movecollision', 'local_bosonanceqbank');
        }
        return [
            'sourceid' => (int)$source->id, 'targetid' => $targetid, 'entryid' => (int)$entry->id,
            'idnumber' => $entry->idnumber, 'ownerid' => $entry->ownerid, 'nextversion' => $entry->nextversion,
            'source' => [(int)$source->parent, $source->name], 'target' => [(int)$target->parent, $target->name],
            'versions' => $versions,
        ];
    }

    private static function state_hash(array $analysis): string {
        return hash('sha256', json_encode($analysis, JSON_THROW_ON_ERROR));
    }
}
