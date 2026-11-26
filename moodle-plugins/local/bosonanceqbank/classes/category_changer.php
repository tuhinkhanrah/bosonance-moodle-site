<?php
namespace local_bosonanceqbank;

defined('MOODLE_INTERNAL') || die();

use local_bosonanceqbank\external\access;

/** Preview-bound category creation and empty-leaf renaming within one bank. */
class category_changer {
    private const TABLE = 'local_bosonanceqbank_category';
    private const TTL = 1800;
    private const SIBLING_LIMIT = 1000;

    public static function preview(int $contextid, int $categoryid, string $action, string $name): array {
        global $DB, $USER;

        self::enabled();
        if ($categoryid < 1 || !in_array($action, ['create', 'rename'], true)
                || $name === '' || $name === '0' || $name !== trim($name)
                || \core_text::strlen($name) > 255 || clean_param($name, PARAM_NOTAGS) !== $name
                || preg_match('/[\/\\\\\x00-\x1f\x7f]/', $name)) {
            throw new \invalid_parameter_exception(get_string('categoryinvalid', 'local_bosonanceqbank'));
        }
        $context = access::bank($contextid);
        $state = self::analyse($context, $categoryid, $action, $name);
        $token = bin2hex(random_bytes(32));
        $now = time();
        $record = (object)[
            'userid' => $USER->id, 'contextid' => $contextid, 'categoryid' => $categoryid,
            'parentid' => $state['parent']['id'], 'action' => $action,
            'oldname' => $action === 'rename' ? $state['category']['name'] : '', 'newname' => $name,
            'resultcategoryid' => 0, 'statehash' => self::state_hash($state), 'tokenhash' => hash('sha256', $token),
            'timecreated' => $now, 'timeexpires' => $now + self::TTL, 'timeused' => 0,
        ];
        $DB->delete_records_select(self::TABLE, 'timeused = 0 AND timeexpires < ?', [$now]);
        $DB->insert_record(self::TABLE, $record);
        return self::result($record) + ['previewtoken' => $token, 'expires' => $record->timeexpires];
    }

    public static function apply(string $token, bool $exclusive): array {
        global $DB, $USER;

        self::enabled();
        if (!preg_match('/^[0-9a-f]{64}$/', $token) || !$exclusive) {
            throw new \invalid_parameter_exception(get_string('categoryconfirmation', 'local_bosonanceqbank'));
        }
        $DB->transactions_forbidden();
        if (!in_array($DB->get_dbfamily(), ['mysql', 'postgres'], true)) {
            throw new \moodle_exception('categorydatabase', 'local_bosonanceqbank');
        }
        $record = $DB->get_record(self::TABLE, ['tokenhash' => hash('sha256', $token)]);
        if (!$record || (int)$record->userid !== (int)$USER->id) {
            throw new \moodle_exception('categorypreviewinvalid', 'local_bosonanceqbank');
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
                    throw new \moodle_exception('categorypreviewinvalid', 'local_bosonanceqbank');
                }
                if ($record->timeused) {
                    throw new \moodle_exception('categorypreviewused', 'local_bosonanceqbank');
                }
                if ($record->timeexpires < time()) {
                    throw new \moodle_exception('categorypreviewexpired', 'local_bosonanceqbank');
                }
                $ids = array_unique([(int)$record->categoryid, (int)$record->parentid]);
                sort($ids);
                foreach ($ids as $id) {
                    $DB->get_record_sql('SELECT id FROM {question_categories} WHERE id = ? FOR UPDATE', [$id], MUST_EXIST);
                }
                $state = self::analyse($context, (int)$record->categoryid, $record->action, $record->newname);
                if (!hash_equals($record->statehash, self::state_hash($state))) {
                    throw new \moodle_exception('categorystale', 'local_bosonanceqbank');
                }
                $manager = new \core_question\category_manager();
                $parent = $record->parentid . ',' . $context->id;
                if ($record->action === 'create') {
                    $resultid = $manager->add_category($parent, $record->newname, '', FORMAT_HTML);
                    $created = access::category((int)$context->id, $resultid);
                    if ($created->name !== $record->newname || (int)$created->parent !== (int)$record->parentid
                            || $created->info !== '' || (int)$created->infoformat !== (int)FORMAT_HTML
                            || $created->idnumber !== null) {
                        throw new \moodle_exception('categoryfailed', 'local_bosonanceqbank');
                    }
                } else {
                    $category = $state['category'];
                    $manager->update_category((int)$record->categoryid, $parent, $record->newname,
                        $category['info'], $category['infoformat'], $category['idnumber']);
                    $resultid = (int)$record->categoryid;
                    $expected = $category;
                    $expected['name'] = $record->newname;
                    if (self::category_state(access::category((int)$context->id, $resultid)) !== $expected) {
                        throw new \moodle_exception('categoryfailed', 'local_bosonanceqbank');
                    }
                }
                $record->resultcategoryid = $resultid;
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
        return ['categoryauditid' => (int)$record->id, 'resultcategoryid' => (int)$record->resultcategoryid]
            + self::result($record);
    }

    private static function enabled(): void {
        global $CFG;
        if (empty($CFG->local_bosonanceqbank_enablecategories)) {
            throw new \moodle_exception('categorydisabled', 'local_bosonanceqbank');
        }
        \core_question\local\bank\helper::require_plugin_enabled('qbank_managecategories');
    }

    private static function analyse(\context $context, int $categoryid, string $action, string $name): array {
        global $DB;

        require_capability('moodle/question:managecategory', $context);
        $category = access::category((int)$context->id, $categoryid);
        if ($action === 'rename' && (!$category->parent
                || $DB->record_exists('question_categories', ['parent' => $categoryid])
                || $DB->record_exists('question_bank_entries', ['questioncategoryid' => $categoryid])
                || $DB->record_exists('question_set_references', ['questionscontextid' => $context->id]))) {
            throw new \moodle_exception('categoryunsupported', 'local_bosonanceqbank');
        }
        $parent = $action === 'create' ? $category : access::category((int)$context->id, (int)$category->parent);
        $siblings = $DB->get_records('question_categories',
            ['parent' => $parent->id, 'contextid' => $context->id], 'id ASC', '*', 0, self::SIBLING_LIMIT + 1);
        if (count($siblings) > self::SIBLING_LIMIT) {
            throw new \moodle_exception('categorylimit', 'local_bosonanceqbank');
        }
        foreach ($siblings as $sibling) {
            if (\core_text::strtolower($sibling->name) === \core_text::strtolower($name)) {
                throw new \moodle_exception('categorynameconflict', 'local_bosonanceqbank');
            }
        }
        return [
            'category' => self::category_state($category), 'parent' => self::category_state($parent),
            'siblings' => array_values(array_map([self::class, 'category_state'], $siblings)),
        ];
    }

    private static function category_state(\stdClass $category): array {
        return [
            'id' => (int)$category->id, 'contextid' => (int)$category->contextid, 'parent' => (int)$category->parent,
            'name' => $category->name, 'info' => $category->info, 'infoformat' => (int)$category->infoformat,
            'idnumber' => $category->idnumber, 'stamp' => $category->stamp, 'sortorder' => (int)$category->sortorder,
        ];
    }

    private static function state_hash(array $state): string {
        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    private static function result(\stdClass $record): array {
        return [
            'contextid' => (int)$record->contextid, 'categoryid' => (int)$record->categoryid,
            'parentid' => (int)$record->parentid, 'action' => $record->action,
            'oldname' => $record->oldname, 'newname' => $record->newname,
        ];
    }
}
