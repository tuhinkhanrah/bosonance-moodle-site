<?php
namespace local_bosonanceqbank;

defined('MOODLE_INTERNAL') || die();

use local_bosonanceqbank\external\access;

/** Irreversible, preview-bound deletion of unused entries and category trees. */
class deleter {
    private const TABLE = 'local_bosonanceqbank_delete';
    private const TTL = 1800;
    private const VERSION_LIMIT = 100;
    private const ENTRY_LIMIT = 100;
    private const TOTAL_VERSION_LIMIT = 1000;
    private const SIBLING_LIMIT = 1000;
    private const CATEGORY_LIMIT = 1000;
    private const FILE_LIMIT = 10000;

    public static function preview(string $kind, int $contextid, int $targetid, int $version = 0,
            bool $recursive = false, bool $deleteunusedquestions = false): array {
        global $DB, $USER;

        self::enabled($kind, $deleteunusedquestions);
        if ($targetid < 1 || ($kind === 'question' && $version < 1)) {
            throw new \invalid_parameter_exception(get_string('deleteinvalid', 'local_bosonanceqbank'));
        }
        $context = access::bank($contextid);
        $state = self::analyse($kind, $context, $targetid, $version, $recursive, null, $deleteunusedquestions);
        $token = bin2hex(random_bytes(32));
        $now = time();
        $record = (object)[
            'userid' => $USER->id, 'contextid' => $contextid, 'kind' => $kind,
            'categoryid' => $state['result']['categoryid'], 'parentid' => $state['result']['parentid'],
            'entryid' => $state['result']['entryid'], 'questionid' => $state['result']['questionid'],
            'version' => $version, 'details' => json_encode($state['result'], JSON_THROW_ON_ERROR),
            'tokenhash' => hash('sha256', $token), 'statehash' => self::state_hash($state),
            'timecreated' => $now, 'timeexpires' => $now + self::TTL, 'timeused' => 0,
        ];
        $DB->delete_records_select(self::TABLE, 'timeused = 0 AND timeexpires < ?', [$now]);
        $DB->insert_record(self::TABLE, $record);
        $result = $state['result'];
        if ($kind === 'category' && isset($result['categories'])) {
            foreach ($result['categories'] as &$item) {
                $item['name'] = $state['tree'][$item['categoryid']]->name;
            }
            unset($item);
        }
        if ($kind === 'category' && isset($result['questionentries'])) {
            foreach ($result['questionentries'] as &$item) {
                $item['name'] = $state['questionstates'][$item['entryid']]['name'];
            }
            unset($item);
        }
        return $result + ['name' => $state['name'], 'previewtoken' => $token, 'expires' => $now + self::TTL];
    }

    public static function apply(string $kind, string $token, bool $exclusive): array {
        global $DB, $USER;

        self::enabled($kind);
        if (!preg_match('/^[0-9a-f]{64}$/', $token) || !$exclusive) {
            throw new \invalid_parameter_exception(get_string('deleteconfirmation', 'local_bosonanceqbank'));
        }
        $DB->transactions_forbidden();
        if (!in_array($DB->get_dbfamily(), ['mysql', 'postgres'], true)) {
            throw new \moodle_exception('deletedatabase', 'local_bosonanceqbank');
        }
        $record = $DB->get_record(self::TABLE, ['tokenhash' => hash('sha256', $token)]);
        if (!$record || $record->kind !== $kind || (int)$record->userid !== (int)$USER->id) {
            throw new \moodle_exception('deletepreviewinvalid', 'local_bosonanceqbank');
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
                if ($record->kind !== $kind || (int)$record->userid !== (int)$USER->id) {
                    throw new \moodle_exception('deletepreviewinvalid', 'local_bosonanceqbank');
                }
                if ($record->timeused) {
                    throw new \moodle_exception('deletepreviewused', 'local_bosonanceqbank');
                }
                if ((int)$record->timeexpires <= time()) {
                    throw new \moodle_exception('deletepreviewexpired', 'local_bosonanceqbank');
                }
                $details = json_decode($record->details, true, 512, JSON_THROW_ON_ERROR);
                self::enabled($kind, $kind === 'category' && !empty($details['deleteunusedquestions']));
                $ids = [(int)$record->categoryid, (int)$record->parentid];
                if ($kind === 'category') {
                    $ids = array_merge($ids, array_column($details['categories'] ?? [], 'categoryid'));
                }
                $ids = array_unique($ids);
                sort($ids);
                foreach ($ids as $id) {
                    if ($id && !$DB->get_record_sql('SELECT id FROM {question_categories} WHERE id = ? FOR UPDATE', [$id])) {
                        throw new \moodle_exception('deletestale', 'local_bosonanceqbank');
                    }
                }
                if ($kind === 'question') {
                    if (!$DB->get_record_sql('SELECT id FROM {question_bank_entries} WHERE id = ? FOR UPDATE',
                            [$record->entryid])) {
                        throw new \moodle_exception('deletestale', 'local_bosonanceqbank');
                    }
                    $DB->get_records_sql('SELECT id FROM {question_versions} WHERE questionbankentryid = ? ORDER BY id FOR UPDATE',
                        [$record->entryid]);
                    $questionids = array_column($details['versions'], 'questionid');
                    sort($questionids);
                    foreach ($questionids as $id) {
                        if (!$DB->get_record_sql('SELECT id FROM {question} WHERE id = ? FOR UPDATE', [$id])) {
                            throw new \moodle_exception('deletestale', 'local_bosonanceqbank');
                        }
                    }
                } else if (!empty($details['deleteunusedquestions'])) {
                    $entryids = array_map('intval', array_column($details['questionentries'], 'entryid'));
                    sort($entryids);
                    foreach ($entryids as $entryid) {
                        if (!$DB->get_record_sql('SELECT id FROM {question_bank_entries} WHERE id = ? FOR UPDATE',
                                [$entryid])) {
                            throw new \moodle_exception('deletestale', 'local_bosonanceqbank');
                        }
                        $DB->get_records_sql('SELECT id FROM {question_versions} WHERE questionbankentryid = ? ORDER BY id FOR UPDATE',
                            [$entryid]);
                    }
                    $questionids = [];
                    foreach ($details['questionentries'] as $entry) {
                        foreach ($entry['versions'] as $version) {
                            $questionids[] = (int)$version['questionid'];
                        }
                    }
                    sort($questionids);
                    foreach ($questionids as $questionid) {
                        if (!$DB->get_record_sql('SELECT id FROM {question} WHERE id = ? FOR UPDATE', [$questionid])) {
                            throw new \moodle_exception('deletestale', 'local_bosonanceqbank');
                        }
                    }
                }
                $targetid = $kind === 'question' ? (int)$record->questionid : (int)$record->categoryid;
                $state = self::analyse($kind, $context, $targetid, (int)$record->version,
                    $kind === 'category' && ($details['recursive'] ?? false), null,
                    $kind === 'category' && ($details['deleteunusedquestions'] ?? false));
                if (!hash_equals($record->statehash, self::state_hash($state))) {
                    throw new \moodle_exception('deletestale', 'local_bosonanceqbank');
                }
                if ($kind === 'category') {
                    if (!empty($details['deleteunusedquestions'])) {
                        foreach ($state['questionstates'] as $questionstate) {
                            self::delete_question_state($questionstate);
                        }
                    }
                    $manager = new \core_question\category_manager();
                    foreach ($state['deleteorder'] as $categoryid) {
                        if ($DB->record_exists('question_categories', ['parent' => $categoryid])
                                || $DB->record_exists('question_bank_entries', ['questioncategoryid' => $categoryid])) {
                            throw new \moodle_exception('deletecategoryoccupied', 'local_bosonanceqbank');
                        }
                        $manager->delete_category($categoryid);
                    }
                    $siblings = $state['siblings'];
                    unset($siblings[$targetid]);
                    [$in, $params] = $DB->get_in_or_equal(array_keys($state['tree']));
                    if ($DB->record_exists_select('question_categories', "id $in", $params)
                            || $DB->record_exists_select('question_bank_entries', "questioncategoryid $in", $params)
                            || $siblings != self::siblings($context, (int)$record->parentid)
                            || $state['parent'] != access::category((int)$context->id, (int)$record->parentid)) {
                        throw new \moodle_exception('deletefailed', 'local_bosonanceqbank');
                    }
                } else {
                    self::delete_question_state($state);
                }
                $record->timeused = time();
                $DB->update_record(self::TABLE, $record);
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
        return json_decode($record->details, true, 512, JSON_THROW_ON_ERROR)
            + ['deletionid' => (int)$record->id, 'deleted' => true];
    }

    private static function enabled(string $kind, bool $deleteunusedquestions = false): void {
        global $CFG;
        if (!in_array($kind, ['question', 'category'], true)) {
            throw new \invalid_parameter_exception(get_string('deleteinvalid', 'local_bosonanceqbank'));
        }
        if (empty($CFG->local_bosonanceqbank_enabledeletions)) {
            throw new \moodle_exception('deletedisabled', 'local_bosonanceqbank');
        }
        \core_question\local\bank\helper::require_plugin_enabled(
            $kind === 'question' ? 'qbank_deletequestion' : 'qbank_managecategories');
        if ($deleteunusedquestions) {
            \core_question\local\bank\helper::require_plugin_enabled('qbank_deletequestion');
        }
    }

    private static function analyse(string $kind, \context $context, int $targetid, int $version,
            bool $recursive = false, ?array $bankfiles = null, bool $deleteunusedquestions = false): array {
        global $DB;

        if ($DB->record_exists('question_set_references', ['questionscontextid' => $context->id])) {
            throw new \moodle_exception('deleteinuse', 'local_bosonanceqbank');
        }
        $result = ['kind' => $kind, 'contextid' => (int)$context->id, 'categoryid' => 0, 'parentid' => 0,
            'entryid' => 0, 'questionid' => 0, 'version' => 0, 'questioncount' => 0, 'versions' => []];
        if ($kind === 'category') {
            $recursive = $recursive || $deleteunusedquestions;
            $category = access::category((int)$context->id, $targetid);
            $manager = new \core_question\category_manager();
            $manager->require_can_delete_category($targetid);
            $tree = [$targetid => $category];
            $queue = [$category];
            for ($index = 0; $index < count($queue); $index++) {
                $children = $DB->get_records('question_categories', ['parent' => $queue[$index]->id],
                    'id ASC', '*', 0, self::CATEGORY_LIMIT + 1);
                if (!$recursive && $children) {
                    throw new \moodle_exception('deletecategoryoccupied', 'local_bosonanceqbank');
                }
                foreach ($children as $child) {
                    if ((int)$child->contextid !== (int)$context->id || isset($tree[$child->id])) {
                        throw new \moodle_exception('deleteunsupported', 'local_bosonanceqbank');
                    }
                    if (count($tree) >= self::CATEGORY_LIMIT) {
                        throw new \moodle_exception('deletelimit', 'local_bosonanceqbank');
                    }
                    $manager->require_can_delete_category((int)$child->id);
                    $tree[(int)$child->id] = $child;
                    $queue[] = $child;
                }
            }
            [$in, $params] = $DB->get_in_or_equal(array_keys($tree));
            $entries = $DB->get_records_select('question_bank_entries', "questioncategoryid $in", $params,
                'id ASC', '*', 0, self::ENTRY_LIMIT + 1);
            if ($entries && !$deleteunusedquestions) {
                throw new \moodle_exception('deletecategoryoccupied', 'local_bosonanceqbank');
            }
            if (count($entries) > self::ENTRY_LIMIT) {
                throw new \moodle_exception('deletelimit', 'local_bosonanceqbank');
            }
            $questionstates = [];
            $questionentries = [];
            $totalversions = 0;
            if ($deleteunusedquestions && $entries) {
                $bankfiles ??= $DB->get_records('files', ['contextid' => $context->id], 'id ASC', '*', 0,
                    self::FILE_LIMIT + 1);
                if (count($bankfiles) > self::FILE_LIMIT) {
                    throw new \moodle_exception('deletelimit', 'local_bosonanceqbank');
                }
                foreach ($entries as $entry) {
                    $latest = $DB->get_records('question_versions', ['questionbankentryid' => $entry->id],
                        'version DESC', '*', 0, 1);
                    if (!$latest) {
                        throw new \moodle_exception('deleteunsupported', 'local_bosonanceqbank');
                    }
                    $latest = reset($latest);
                    $questionstate = self::analyse('question', $context, (int)$latest->questionid,
                        (int)$latest->version, false, $bankfiles);
                    $totalversions += $questionstate['result']['questioncount'];
                    if ($totalversions > self::TOTAL_VERSION_LIMIT) {
                        throw new \moodle_exception('deletelimit', 'local_bosonanceqbank');
                    }
                    $entryid = (int)$entry->id;
                    $latestquestion = end($questionstate['questions']);
                    $questionstates[$entryid] = $questionstate;
                    unset($questionstates[$entryid]['files']);
                    $questionentries[] = [
                        'entryid' => $entryid,
                        'categoryid' => (int)$questionstate['result']['categoryid'],
                        'questionid' => (int)$questionstate['result']['questionid'],
                        'version' => (int)$questionstate['result']['version'],
                        'qtype' => $latestquestion->qtype,
                        'questioncount' => (int)$questionstate['result']['questioncount'],
                        'versions' => $questionstate['result']['versions'],
                    ];
                }
            }
            $deleteorder = array_reverse(array_map(static fn($item) => (int)$item->id, $queue));
            ksort($tree);
            $result['categoryid'] = $targetid;
            $result['parentid'] = (int)$category->parent;
            if ($recursive) {
                $result['recursive'] = true;
                $result['categorycount'] = count($tree);
                $result['categories'] = array_values(array_map(static fn($item) => [
                    'categoryid' => (int)$item->id, 'parentid' => (int)$item->parent,
                ], $tree));
            }
            if ($deleteunusedquestions) {
                $result['deleteunusedquestions'] = true;
                $result['entrycount'] = count($questionentries);
                $result['questioncount'] = $totalversions;
                $result['questionentries'] = $questionentries;
            }
            $state = ['result' => $result, 'name' => $category->name, 'tree' => $tree,
                'deleteorder' => $deleteorder,
                'parent' => access::category((int)$context->id, (int)$category->parent),
                'siblings' => self::siblings($context, (int)$category->parent),
                'questionstates' => $questionstates];
            if ($deleteunusedquestions && $entries) {
                $state['files'] = $bankfiles;
            }
            return $state;
        }
        $question = access::question((int)$context->id, $targetid);
        $entry = $DB->get_record('question_bank_entries', ['id' => $question->questionbankentryid], '*', MUST_EXIST);
        $category = access::category((int)$context->id, (int)$entry->questioncategoryid);
        $rows = $DB->get_records('question_versions', ['questionbankentryid' => $entry->id],
            'version ASC', '*', 0, self::VERSION_LIMIT + 1);
        if (!$rows || count($rows) > self::VERSION_LIMIT) {
            throw new \moodle_exception('deletelimit', 'local_bosonanceqbank');
        }
        $questions = [];
        foreach ($rows as $row) {
            $id = (int)$row->questionid;
            if ($DB->count_records('question_versions', ['questionid' => $id]) !== 1) {
                throw new \moodle_exception('deleteunsupported', 'local_bosonanceqbank');
            }
            $data = $DB->get_record('question', ['id' => $id], '*', MUST_EXIST);
            question_require_capability_on($id, 'edit');
            if ($data->parent || !\question_bank::is_qtype_installed($data->qtype)) {
                throw new \moodle_exception('deleteunsupported', 'local_bosonanceqbank');
            }
            $data->category = (int)$category->id;
            $data->export_process = true;
            get_question_options($data, false);
            if (!isset($data->options)) {
                throw new \moodle_exception('deleteunsupported', 'local_bosonanceqbank');
            }
            $questions[] = $data;
            $result['versions'][] = ['questionid' => $id, 'version' => (int)$row->version,
                'status' => $row->status, 'timemodified' => (int)$data->timemodified];
        }
        $latest = end($result['versions']);
        if ($latest['questionid'] !== $targetid || $latest['version'] !== $version) {
            throw new \moodle_exception('deletestale', 'local_bosonanceqbank');
        }
        $ids = array_column($result['versions'], 'questionid');
        [$in, $params] = $DB->get_in_or_equal($ids);
        if ($DB->record_exists_select('question', "parent $in", $params)) {
            throw new \moodle_exception('deleteunsupported', 'local_bosonanceqbank');
        }
        if ($DB->record_exists('question_references', ['questionbankentryid' => $entry->id])
                || $DB->record_exists_select('question_attempts', "questionid $in", $params)
                || questions_in_use($ids)) {
            throw new \moodle_exception('deleteinuse', 'local_bosonanceqbank');
        }
        $tags = $DB->get_records_select('tag_instance',
            "component = 'core_question' AND itemtype = 'question' AND itemid $in", $params, 'id ASC');
        $files = $bankfiles ?? $DB->get_records('files', ['contextid' => $context->id], 'id ASC', '*', 0,
            self::FILE_LIMIT + 1);
        if (count($files) > self::FILE_LIMIT) {
            throw new \moodle_exception('deletelimit', 'local_bosonanceqbank');
        }
        $answers = $DB->get_records_select('question_answers', "question $in", $params, 'id ASC');
        $hints = $DB->get_records_select('question_hints', "questionid $in", $params, 'id ASC');
        $ownedfiles = [];
        foreach ($files as $file) {
            if ($file->component !== 'question') {
                continue;
            }
            $itemid = (int)$file->itemid;
            if ((in_array($file->filearea, ['questiontext', 'generalfeedback', 'correctfeedback',
                    'partiallycorrectfeedback', 'incorrectfeedback'], true) && in_array($itemid, $ids, true))
                    || (in_array($file->filearea, ['answer', 'answerfeedback'], true) && isset($answers[$itemid]))
                    || ($file->filearea === 'hint' && isset($hints[$itemid]))) {
                $ownedfiles[] = (int)$file->id;
            }
        }
        $result['categoryid'] = (int)$category->id;
        $result['parentid'] = (int)$category->parent;
        $result['entryid'] = (int)$entry->id;
        $result['questionid'] = $targetid;
        $result['version'] = $version;
        $result['questioncount'] = count($rows);
        return ['result' => $result, 'name' => $question->name, 'entry' => $entry, 'category' => $category,
            'rows' => array_values($rows), 'questions' => $questions, 'tags' => $tags, 'files' => $files,
            'answers' => $answers, 'hints' => $hints, 'ownedfiles' => $ownedfiles];
    }

    private static function siblings(\context $context, int $parentid): array {
        global $DB;
        $siblings = $DB->get_records('question_categories', ['contextid' => $context->id, 'parent' => $parentid],
            'id ASC', '*', 0, self::SIBLING_LIMIT + 1);
        if (count($siblings) > self::SIBLING_LIMIT) {
            throw new \moodle_exception('deletelimit', 'local_bosonanceqbank');
        }
        return $siblings;
    }

    private static function delete_question_state(array $state): void {
        global $DB;

        foreach ($state['result']['versions'] as $version) {
            question_delete_question($version['questionid']);
        }
        if ($DB->record_exists('question_bank_entries', ['id' => $state['result']['entryid']])
                || $DB->record_exists('question_versions', ['questionbankentryid' => $state['result']['entryid']])) {
            throw new \moodle_exception('deletefailed', 'local_bosonanceqbank');
        }
        foreach ($state['result']['versions'] as $version) {
            if ($DB->record_exists('question', ['id' => $version['questionid']])
                    || $DB->record_exists('question_answers', ['question' => $version['questionid']])
                    || $DB->record_exists('question_hints', ['questionid' => $version['questionid']])) {
                throw new \moodle_exception('deletefailed', 'local_bosonanceqbank');
            }
        }
        if ($state['ownedfiles']) {
            [$in, $params] = $DB->get_in_or_equal($state['ownedfiles']);
            if ($DB->record_exists_select('files', "id $in", $params)) {
                throw new \moodle_exception('deletefailed', 'local_bosonanceqbank');
            }
        }
        \core_tag_tag::delete_instances_by_id(array_keys($state['tags']));
        $questionids = array_column($state['result']['versions'], 'questionid');
        [$in, $params] = $DB->get_in_or_equal($questionids);
        if ($DB->record_exists_select('tag_instance',
                "component = 'core_question' AND itemtype = 'question' AND itemid $in", $params)) {
            throw new \moodle_exception('deletefailed', 'local_bosonanceqbank');
        }
    }

    private static function state_hash(array $state): string {
        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }
}
