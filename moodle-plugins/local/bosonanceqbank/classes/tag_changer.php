<?php
namespace local_bosonanceqbank;

defined('MOODLE_INTERNAL') || die();

use local_bosonanceqbank\external\access;

/** Preview-bound replacement of standard tag assignments on one question version. */
class tag_changer {
    private const TABLE = 'local_bosonanceqbank_tags';
    private const TTL = 1800;
    private const LIMIT = 50;

    public static function preview(int $contextid, int $questionid, int $expectedversion, array $tagids): array {
        global $DB, $USER;

        self::enabled();
        if ($questionid < 1 || $expectedversion < 1 || count($tagids) > self::LIMIT
                || count(array_unique($tagids)) !== count($tagids)) {
            throw new \invalid_parameter_exception(get_string('tagsinvalid', 'local_bosonanceqbank'));
        }
        foreach ($tagids as $tagid) {
            if (!is_int($tagid) || $tagid < 1) {
                throw new \invalid_parameter_exception(get_string('tagsinvalid', 'local_bosonanceqbank'));
            }
        }
        $context = access::bank($contextid);
        $state = self::analyse($context, $questionid, $expectedversion, $tagids);
        if (array_column($state['oldtags'], 'id') === $tagids) {
            throw new \invalid_parameter_exception(get_string('tagsunchanged', 'local_bosonanceqbank'));
        }
        $token = bin2hex(random_bytes(32));
        $now = time();
        $DB->delete_records_select(self::TABLE, 'timeused = 0 AND timeexpires < ?', [$now]);
        $DB->insert_record(self::TABLE, (object)[
            'userid' => $USER->id, 'contextid' => $contextid, 'categoryid' => $state['categoryid'],
            'entryid' => $state['entryid'], 'questionid' => $questionid, 'version' => $expectedversion,
            'oldtags' => json_encode(array_column($state['oldtags'], 'id'), JSON_THROW_ON_ERROR),
            'newtags' => json_encode($tagids, JSON_THROW_ON_ERROR),
            'statehash' => self::state_hash($state), 'tokenhash' => hash('sha256', $token),
            'timecreated' => $now, 'timeexpires' => $now + self::TTL, 'timeused' => 0,
        ]);
        return [
            'contextid' => $contextid, 'categoryid' => $state['categoryid'], 'entryid' => $state['entryid'],
            'questionid' => $questionid, 'version' => $expectedversion, 'oldtags' => $state['oldtags'],
            'newtags' => $state['newtags'], 'previewtoken' => $token, 'expires' => $now + self::TTL,
        ];
    }

    public static function apply(string $token, bool $exclusive): array {
        global $DB, $USER;

        self::enabled();
        if (!preg_match('/^[0-9a-f]{64}$/', $token) || !$exclusive) {
            throw new \invalid_parameter_exception(get_string('tagsconfirmation', 'local_bosonanceqbank'));
        }
        $DB->transactions_forbidden();
        if (!in_array($DB->get_dbfamily(), ['mysql', 'postgres'], true)) {
            throw new \moodle_exception('tagsdatabase', 'local_bosonanceqbank');
        }
        $record = $DB->get_record(self::TABLE, ['tokenhash' => hash('sha256', $token)]);
        if (!$record || (int)$record->userid !== (int)$USER->id) {
            throw new \moodle_exception('tagspreviewinvalid', 'local_bosonanceqbank');
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
                    throw new \moodle_exception('tagspreviewinvalid', 'local_bosonanceqbank');
                }
                if ($record->timeused) {
                    throw new \moodle_exception('tagspreviewused', 'local_bosonanceqbank');
                }
                if ($record->timeexpires < time()) {
                    throw new \moodle_exception('tagspreviewexpired', 'local_bosonanceqbank');
                }
                $DB->get_record_sql('SELECT id FROM {question_categories} WHERE id = ? FOR UPDATE',
                    [$record->categoryid], MUST_EXIST);
                $DB->get_record_sql('SELECT id FROM {question_bank_entries} WHERE id = ? FOR UPDATE',
                    [$record->entryid], MUST_EXIST);
                $DB->get_records_sql('SELECT id FROM {question_versions} WHERE questionbankentryid = ? FOR UPDATE',
                    [$record->entryid]);
                $DB->get_record_sql('SELECT id FROM {question} WHERE id = ? FOR UPDATE', [$record->questionid], MUST_EXIST);
                $DB->get_records_sql(
                    'SELECT id FROM {tag_instance} WHERE component = ? AND itemtype = ? AND itemid = ? FOR UPDATE',
                    ['core_question', 'question', $record->questionid]);
                $tagids = json_decode($record->newtags, true, 512, JSON_THROW_ON_ERROR);
                $oldids = json_decode($record->oldtags, true, 512, JSON_THROW_ON_ERROR);
                $lockids = array_values(array_unique(array_merge($oldids, $tagids)));
                sort($lockids);
                if ($lockids) {
                    [$in, $params] = $DB->get_in_or_equal($lockids);
                    $DB->get_records_sql("SELECT id FROM {tag} WHERE id $in ORDER BY id FOR UPDATE", $params);
                }
                $state = self::analyse($context, (int)$record->questionid, (int)$record->version, $tagids);
                if ($state['entryid'] !== (int)$record->entryid || $state['categoryid'] !== (int)$record->categoryid
                        || !hash_equals($record->statehash, self::state_hash($state))) {
                    throw new \moodle_exception('tagsstale', 'local_bosonanceqbank');
                }
                \core_tag_tag::set_item_tags('core_question', 'question', (int)$record->questionid, $context,
                    array_column($state['newtags'], 'name'));
                $after = self::analyse($context, (int)$record->questionid, (int)$record->version, $tagids);
                if ($after['oldtags'] !== $state['newtags']) {
                    throw new \moodle_exception('tagsfailed', 'local_bosonanceqbank');
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
            'tagsid' => (int)$record->id, 'contextid' => (int)$record->contextid,
            'categoryid' => (int)$record->categoryid, 'entryid' => (int)$record->entryid,
            'questionid' => (int)$record->questionid, 'version' => (int)$record->version,
            'oldtags' => $state['oldtags'], 'newtags' => $state['newtags'],
        ];
    }

    private static function enabled(): void {
        global $CFG;
        if (empty($CFG->local_bosonanceqbank_enabletags)) {
            throw new \moodle_exception('tagsdisabled', 'local_bosonanceqbank');
        }
        \core_question\local\bank\helper::require_plugin_enabled('qbank_tagquestion');
        if (!\core_tag_tag::is_enabled('core_question', 'question')) {
            throw new \moodle_exception('tagsdisabled', 'local_bosonanceqbank');
        }
    }

    private static function analyse(\context $context, int $questionid, int $expectedversion, array $tagids): array {
        global $DB;

        if ($DB->count_records('question_versions', ['questionid' => $questionid]) !== 1) {
            throw new \moodle_exception('tagsstale', 'local_bosonanceqbank');
        }
        $question = access::question((int)$context->id, $questionid);
        question_require_capability_on($questionid, 'tag');
        $entry = $DB->get_record('question_bank_entries', ['id' => $question->questionbankentryid], '*', MUST_EXIST);
        $latest = $DB->get_record_sql(
            'SELECT id, questionid, version, status FROM {question_versions}
              WHERE questionbankentryid = ? ORDER BY version DESC', [$entry->id], IGNORE_MULTIPLE);
        if (!$latest || (int)$latest->questionid !== $questionid || (int)$latest->version !== $expectedversion) {
            throw new \moodle_exception('tagsstale', 'local_bosonanceqbank');
        }
        if ($question->parent || $DB->record_exists('question', ['parent' => $questionid])) {
            throw new \moodle_exception('tagschildren', 'local_bosonanceqbank');
        }
        if ($DB->record_exists('question_references', ['questionbankentryid' => $entry->id])
                || $DB->record_exists('question_set_references', ['questionscontextid' => $context->id])) {
            throw new \moodle_exception('tagsinuse', 'local_bosonanceqbank');
        }
        $collection = \core_tag_area::get_collection('core_question', 'question');
        $old = [];
        $instances = $DB->get_records_sql(
            'SELECT ti.id, ti.contextid, ti.tiuserid, ti.ordering, t.id AS tagid, t.rawname, t.name,
                    t.isstandard, t.tagcollid
               FROM {tag_instance} ti JOIN {tag} t ON t.id = ti.tagid
              WHERE ti.component = ? AND ti.itemtype = ? AND ti.itemid = ?
              ORDER BY ti.ordering, ti.id', ['core_question', 'question', $questionid]);
        foreach ($instances as $instance) {
            if ((int)$instance->contextid !== (int)$context->id || (int)$instance->tiuserid !== 0
                    || !(int)$instance->isstandard || (int)$instance->tagcollid !== (int)$collection) {
                throw new \moodle_exception('tagsunsupported', 'local_bosonanceqbank');
            }
            $old[] = ['id' => (int)$instance->tagid, 'name' => $instance->rawname];
        }
        if (count($old) > self::LIMIT || count(array_unique(array_column($old, 'id'))) !== count($old)) {
            throw new \moodle_exception('tagsunsupported', 'local_bosonanceqbank');
        }
        $records = $tagids ? $DB->get_records_list('tag', 'id', $tagids) : [];
        $new = [];
        $names = [];
        foreach ($tagids as $tagid) {
            if (!isset($records[$tagid])) {
                throw new \moodle_exception('tagsunsupported', 'local_bosonanceqbank');
            }
            $tag = $records[$tagid];
            $raw = array_filter(\core_tag_tag::normalize([$tag->rawname], false));
            $normalisednames = \core_tag_tag::normalize($raw);
            $normalised = reset($normalisednames);
            if (!$normalised || !(int)$tag->isstandard || (int)$tag->tagcollid !== (int)$collection
                    || $normalised !== $tag->name
                    || in_array($normalised, $names, true)) {
                throw new \moodle_exception('tagsunsupported', 'local_bosonanceqbank');
            }
            $names[] = $normalised;
            $new[] = ['id' => (int)$tag->id, 'name' => $tag->rawname];
        }
        return [
            'categoryid' => (int)$question->categoryid, 'entryid' => (int)$entry->id, 'questionid' => $questionid,
            'version' => (int)$latest->version, 'status' => $latest->status, 'timemodified' => (int)$question->timemodified,
            'ownerid' => $entry->ownerid, 'oldtags' => $old, 'newtags' => $new,
        ];
    }

    private static function state_hash(array $state): string {
        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }
}
