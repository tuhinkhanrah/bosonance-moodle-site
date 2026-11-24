<?php
namespace local_bosonanceqbank;

defined('MOODLE_INTERNAL') || die();

use core_question\local\bank\question_version_status as status;
use local_bosonanceqbank\external\access;

/** Preview-bound status changes for one unused latest question version. */
class status_changer {
    private const TABLE = 'local_bosonanceqbank_status';
    private const TTL = 1800;
    private const STATUSES = [status::QUESTION_STATUS_READY, status::QUESTION_STATUS_DRAFT, status::QUESTION_STATUS_HIDDEN];

    public static function preview(int $contextid, int $questionid, int $expectedversion,
            string $expectedstatus, string $targetstatus): array {
        global $DB, $USER;

        self::enabled();
        if ($questionid < 1 || $expectedversion < 1 || !in_array($expectedstatus, self::STATUSES, true)
                || !in_array($targetstatus, self::STATUSES, true) || $expectedstatus === $targetstatus) {
            throw new \invalid_parameter_exception(get_string('statustarget', 'local_bosonanceqbank'));
        }
        $context = access::bank($contextid);
        $state = self::analyse($context, $questionid, $expectedversion);
        if ($state['status'] !== $expectedstatus) {
            throw new \moodle_exception('statusstale', 'local_bosonanceqbank');
        }
        $token = bin2hex(random_bytes(32));
        $now = time();
        $DB->delete_records_select(self::TABLE, 'timeused = 0 AND timeexpires < ?', [$now]);
        $DB->insert_record(self::TABLE, (object)[
            'userid' => $USER->id, 'contextid' => $contextid, 'categoryid' => $state['categoryid'],
            'entryid' => $state['entryid'], 'questionid' => $questionid, 'version' => $expectedversion,
            'oldstatus' => $expectedstatus, 'newstatus' => $targetstatus,
            'tokenhash' => hash('sha256', $token), 'statehash' => self::state_hash($state),
            'timecreated' => $now, 'timeexpires' => $now + self::TTL, 'timeused' => 0,
        ]);
        return [
            'contextid' => $contextid, 'categoryid' => $state['categoryid'], 'entryid' => $state['entryid'],
            'questionid' => $questionid, 'version' => $expectedversion, 'oldstatus' => $expectedstatus,
            'newstatus' => $targetstatus, 'previewtoken' => $token, 'expires' => $now + self::TTL,
        ];
    }

    public static function apply(string $token, bool $exclusive): array {
        global $DB, $USER;

        self::enabled();
        if (!preg_match('/^[0-9a-f]{64}$/', $token) || !$exclusive) {
            throw new \invalid_parameter_exception(get_string('statusconfirmation', 'local_bosonanceqbank'));
        }
        $DB->transactions_forbidden();
        if (!in_array($DB->get_dbfamily(), ['mysql', 'postgres'], true)) {
            throw new \moodle_exception('statusdatabase', 'local_bosonanceqbank');
        }
        $record = $DB->get_record(self::TABLE, ['tokenhash' => hash('sha256', $token)]);
        if (!$record || (int)$record->userid !== (int)$USER->id) {
            throw new \moodle_exception('statuspreviewinvalid', 'local_bosonanceqbank');
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
                    throw new \moodle_exception('statuspreviewinvalid', 'local_bosonanceqbank');
                }
                if ($record->timeused) {
                    throw new \moodle_exception('statuspreviewused', 'local_bosonanceqbank');
                }
                if ($record->timeexpires < time()) {
                    throw new \moodle_exception('statuspreviewexpired', 'local_bosonanceqbank');
                }
                $DB->get_record_sql('SELECT id FROM {question_categories} WHERE id = ? FOR UPDATE',
                    [$record->categoryid], MUST_EXIST);
                $DB->get_record_sql('SELECT id FROM {question_bank_entries} WHERE id = ? FOR UPDATE',
                    [$record->entryid], MUST_EXIST);
                $DB->get_records_sql('SELECT id FROM {question_versions} WHERE questionbankentryid = ? FOR UPDATE',
                    [$record->entryid]);
                $DB->get_record_sql('SELECT id FROM {question} WHERE id = ? FOR UPDATE', [$record->questionid], MUST_EXIST);
                $state = self::analyse($context, (int)$record->questionid, (int)$record->version);
                if ($state['entryid'] !== (int)$record->entryid || $state['categoryid'] !== (int)$record->categoryid
                        || $state['status'] !== $record->oldstatus
                        || !hash_equals($record->statehash, self::state_hash($state))) {
                    throw new \moodle_exception('statusstale', 'local_bosonanceqbank');
                }
                if ($record->newstatus === status::QUESTION_STATUS_HIDDEN) {
                    // Moodle's delete helper may permanently delete unused questions; never call it to hide.
                    $question = \question_bank::load_question((int)$record->questionid);
                    $DB->set_field('question_versions', 'status', status::QUESTION_STATUS_HIDDEN,
                        ['id' => $state['versionid']]);
                    \question_bank::notify_question_edited((int)$record->questionid);
                    \core\event\question_updated::create_from_question_instance($question, $context)->trigger();
                } else {
                    $result = \qbank_editquestion\external\update_question_version_status::execute(
                        (int)$record->questionid, $record->newstatus);
                    if (!$result['status']) {
                        throw new \moodle_exception('statusfailed', 'local_bosonanceqbank');
                    }
                }
                $changed = $DB->get_record('question_versions', ['questionid' => $record->questionid], '*', MUST_EXIST);
                if ($changed->status !== $record->newstatus || (int)$changed->version !== (int)$record->version
                        || (int)$changed->questionbankentryid !== (int)$record->entryid) {
                    throw new \moodle_exception('statusfailed', 'local_bosonanceqbank');
                }
                $DB->set_field(self::TABLE, 'timeused', time(), ['id' => $record->id]);
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
            'statusid' => (int)$record->id, 'contextid' => (int)$record->contextid,
            'categoryid' => (int)$record->categoryid, 'entryid' => (int)$record->entryid,
            'questionid' => (int)$record->questionid, 'version' => (int)$record->version,
            'oldstatus' => $record->oldstatus, 'newstatus' => $record->newstatus,
        ];
    }

    private static function enabled(): void {
        global $CFG;
        if (empty($CFG->local_bosonanceqbank_enablestatus)) {
            throw new \moodle_exception('statusdisabled', 'local_bosonanceqbank');
        }
        \core_question\local\bank\helper::require_plugin_enabled('qbank_editquestion');
    }

    private static function analyse(\context $context, int $questionid, int $expectedversion): array {
        global $DB;

        if ($DB->count_records('question_versions', ['questionid' => $questionid]) !== 1) {
            throw new \moodle_exception('statusambiguous', 'local_bosonanceqbank');
        }
        $question = access::question((int)$context->id, $questionid);
        question_require_capability_on($questionid, 'edit');
        $entry = $DB->get_record('question_bank_entries', ['id' => $question->questionbankentryid], '*', MUST_EXIST);
        $rows = $DB->get_records_sql(
            'SELECT qv.id, qv.questionid, qv.version, qv.status, q.parent, q.timemodified
               FROM {question_versions} qv JOIN {question} q ON q.id = qv.questionid
              WHERE qv.questionbankentryid = ? ORDER BY qv.version ASC', [$entry->id]);
        $versions = [];
        $ids = [];
        foreach ($rows as $row) {
            if ($row->parent) {
                throw new \moodle_exception('statuschildren', 'local_bosonanceqbank');
            }
            $ids[] = (int)$row->questionid;
            $versions[] = [(int)$row->questionid, (int)$row->version, $row->status, (int)$row->timemodified, (int)$row->id];
        }
        $latest = end($versions);
        if (!$latest || $latest[0] !== $questionid || $latest[1] !== $expectedversion) {
            throw new \moodle_exception('statusstale', 'local_bosonanceqbank');
        }
        [$in, $params] = $DB->get_in_or_equal($ids);
        if ($DB->record_exists_select('question', "parent $in", $params)) {
            throw new \moodle_exception('statuschildren', 'local_bosonanceqbank');
        }
        if ($DB->record_exists('question_references', ['questionbankentryid' => $entry->id])
                || $DB->record_exists_select('question_attempts', "questionid $in", $params)
                || questions_in_use($ids)
                || $DB->record_exists('question_set_references', ['questionscontextid' => $context->id])) {
            throw new \moodle_exception('statusinuse', 'local_bosonanceqbank');
        }
        return [
            'categoryid' => (int)$question->categoryid, 'entryid' => (int)$entry->id,
            'ownerid' => $entry->ownerid, 'idnumber' => $entry->idnumber, 'nextversion' => $entry->nextversion,
            'status' => $latest[2], 'versionid' => $latest[4], 'versions' => $versions,
        ];
    }

    private static function state_hash(array $state): string {
        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }
}
