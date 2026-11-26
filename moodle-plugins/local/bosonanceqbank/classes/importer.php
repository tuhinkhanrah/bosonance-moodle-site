<?php
namespace local_bosonanceqbank;

defined('MOODLE_INTERNAL') || die();

use local_bosonanceqbank\external\access;

/**
 * Preview-bound, additive Moodle XML import.
 */
class importer {
    /** Seconds a preview token stays valid. */
    public const PREVIEW_TTL = 1800;
    private const PREVIEWS = 'local_bosonanceqbank_preview';
    private const IMPORTS = 'local_bosonanceqbank_import';

    public static function preview(int $contextid, int $categoryid, int $draftitemid, string $filename): array {
        global $DB, $USER;

        $context = access::bank($contextid);
        $category = access::category($contextid, $categoryid);
        require_capability('moodle/question:add', $context);
        $xml = self::draft_file($draftitemid, $filename)->get_content();
        $analysis = self::analyse($context, $category, $xml);
        $token = bin2hex(random_bytes(32));
        $now = time();
        $DB->delete_records_select(self::PREVIEWS, 'timeexpires < ?', [$now]);
        $DB->insert_record(self::PREVIEWS, (object)[
            'userid' => $USER->id, 'contextid' => $contextid, 'categoryid' => $categoryid,
            'draftitemid' => $draftitemid, 'filename' => $filename, 'sha256' => $analysis['sha256'],
            'tokenhash' => hash('sha256', $token), 'warningshash' => self::warnings_hash($analysis['warnings']),
            'fingerprint' => self::fingerprint($analysis), 'questioncount' => $analysis['questioncount'],
            'timecreated' => $now, 'timeexpires' => $now + self::PREVIEW_TTL, 'timeused' => 0,
        ]);
        return [
            'contextid' => $contextid,
            'categoryid' => $categoryid,
            'sha256' => $analysis['sha256'],
            'questioncount' => $analysis['questioncount'],
            'categories' => $analysis['categories'],
            'tags' => $analysis['tags'],
            'warnings' => $analysis['warnings'],
            'warningshash' => self::warnings_hash($analysis['warnings']),
            'previewtoken' => $token,
            'expires' => $now + self::PREVIEW_TTL,
            'canapply' => true,
            'requiresduplicateoverride' => $analysis['exactrepeat'],
        ];
    }

    public static function apply(string $token, string $warningshash, bool $allowduplicate): array {
        global $DB, $USER;

        if (!preg_match('/^[0-9a-f]{64}$/', $token) || !preg_match('/^[0-9a-f]{64}$/', $warningshash)) {
            throw new \invalid_parameter_exception(get_string('previewinvalid', 'local_bosonanceqbank'));
        }
        $DB->transactions_forbidden();
        $preview = $DB->get_record(self::PREVIEWS, ['tokenhash' => hash('sha256', $token)]);
        if (!$preview || (int)$preview->userid !== (int)$USER->id) {
            throw new \moodle_exception('previewinvalid', 'local_bosonanceqbank');
        }
        $context = access::bank((int)$preview->contextid);
        \core_question\local\bank\helper::require_plugin_enabled('qbank_importquestions');
        require_capability('moodle/question:add', $context);

        $lock = \core\lock\lock_config::get_lock_factory('local_bosonanceqbank_import')
            ->get_lock('context_' . $context->id, 30);
        if (!$lock) {
            throw new \moodle_exception('importbusy', 'local_bosonanceqbank');
        }
        try {
            $preview = $DB->get_record(self::PREVIEWS, ['id' => $preview->id], '*', MUST_EXIST);
            if ($preview->timeused) {
                throw new \moodle_exception('previewused', 'local_bosonanceqbank');
            }
            if ($preview->timeexpires < time()) {
                throw new \moodle_exception('previewexpired', 'local_bosonanceqbank');
            }
            if (!hash_equals($preview->warningshash, $warningshash)) {
                throw new \moodle_exception('acknowledgement', 'local_bosonanceqbank');
            }
            $category = access::category((int)$preview->contextid, (int)$preview->categoryid);
            $xml = self::draft_file((int)$preview->draftitemid, $preview->filename)->get_content();
            if (!hash_equals($preview->sha256, hash('sha256', $xml))) {
                throw new \moodle_exception('previewstale', 'local_bosonanceqbank');
            }
            $analysis = self::analyse($context, $category, $xml);
            if (!hash_equals($preview->fingerprint, self::fingerprint($analysis))) {
                throw new \moodle_exception('previewstale', 'local_bosonanceqbank');
            }
            if ($analysis['exactrepeat'] && !$allowduplicate) {
                throw new \moodle_exception('duplicateimport', 'local_bosonanceqbank');
            }
            return self::import($context, $category, $preview, $xml, $analysis, $allowduplicate);
        } finally {
            $lock->release();
        }
    }

    private static function import(\context $context, \stdClass $category, \stdClass $preview, string $xml,
            array $analysis, bool $allowduplicate): array {
        global $DB, $USER;

        $path = make_request_directory() . '/import.xml';
        file_put_contents($path, $xml);
        $format = self::format($context, $category);
        $format->setFilename($path);
        $format->setRealfilename($preview->filename);
        $format->existingpaths = array_column(
            array_filter($analysis['categories'], static fn($c) => $c['action'] === 'reuse'), 'path'
        );
        $before = self::entry_count((int)$category->id);
        $level = ob_get_level();
        $transaction = $DB->start_delegated_transaction();
        try {
            $expectedtags = self::prepare_tags($analysis['tags']);
            ob_start();
            $ok = $format->importpreprocess() && $format->importprocess() && $format->importpostprocess();
            $output = ob_get_clean();
            if (!$ok || !$DB->is_transaction_started()) {
                throw new \moodle_exception('importfailed', 'local_bosonanceqbank', '', self::plain($output));
            }
            $ids = array_map('intval', $format->questionids);
            if (count($ids) !== $analysis['questioncount']
                    || self::entry_count((int)$category->id) !== $before + count($ids)) {
                throw new \moodle_exception('importfailed', 'local_bosonanceqbank', '', 'question count mismatch');
            }
            $rootpath = self::category_path($category, (int)$context->id);
            $categories = [];
            foreach ($analysis['categories'] as $item) {
                $item['categoryid'] = self::resolve_path((int)$context->id, $category, $rootpath, $item['path']);
                if (!$item['categoryid']) {
                    throw new \moodle_exception('importfailed', 'local_bosonanceqbank', '', 'category missing');
                }
                $categories[$item['path']] = $item;
            }
            [$in, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
            $rows = $DB->get_records_sql(
                "SELECT q.id, q.name, q.qtype, qbe.questioncategoryid
                   FROM {question} q
                   JOIN {question_versions} qv ON qv.questionid = q.id
                   JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                  WHERE q.id $in", $params);
            $questions = [];
            foreach ($analysis['questions'] as $index => $parsed) {
                $row = $rows[$ids[$index]] ?? null;
                $expected = $parsed['path'] === '' ? (int)$category->id : $categories[$parsed['path']]['categoryid'];
                if (!$row || $row->qtype !== $parsed['qtype'] || (int)$row->questioncategoryid !== $expected) {
                    throw new \moodle_exception('importfailed', 'local_bosonanceqbank', '', 'unexpected destination');
                }
                $questions[] = [
                    'index' => $index + 1, 'questionid' => (int)$row->id, 'categoryid' => $expected,
                    'name' => $row->name, 'qtype' => $row->qtype,
                ];
            }
            $tagparams = $params + ['component' => 'core_question', 'itemtype' => 'question'];
            $tagrows = $DB->get_records_sql(
                "SELECT DISTINCT ti.tagid FROM {tag_instance} ti
                  WHERE ti.component = :component AND ti.itemtype = :itemtype AND ti.itemid $in", $tagparams);
            $actualtags = array_map('intval', array_keys($tagrows));
            sort($actualtags);
            sort($expectedtags);
            if ($actualtags !== $expectedtags) {
                throw new \moodle_exception('importfailed', 'local_bosonanceqbank', '', 'unexpected question tags');
            }
            $now = time();
            $importid = $DB->insert_record(self::IMPORTS, (object)[
                'previewid' => $preview->id, 'userid' => $USER->id, 'contextid' => $context->id,
                'categoryid' => $category->id, 'sha256' => $analysis['sha256'], 'filename' => $preview->filename,
                'questioncount' => count($ids), 'questionids' => json_encode($ids),
                'duplicateoverride' => (int)$analysis['exactrepeat'], 'timecreated' => $now,
            ]);
            $DB->set_field(self::PREVIEWS, 'timeused', $now, ['id' => $preview->id]);
            \core\event\questions_imported::create([
                'context' => $context, 'other' => ['format' => 'xml', 'categoryid' => (int)$category->id],
            ])->trigger();
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            // This method forbids outer transactions, so a full rollback only discards this import.
            if ($DB->is_transaction_started()) {
                $DB->force_transaction_rollback();
            }
            throw $e;
        }
        return [
            'importid' => (int)$importid,
            'contextid' => (int)$context->id,
            'categoryid' => (int)$category->id,
            'sha256' => $analysis['sha256'],
            'questioncount' => count($questions),
            'duplicateoverride' => $analysis['exactrepeat'],
            'categories' => array_values($categories),
            'questions' => $questions,
        ];
    }

    private static function analyse(\context $context, \stdClass $category, string $xml): array {
        global $DB;

        $contextid = (int)$context->id;
        $categoryid = (int)$category->id;
        $sha256 = hash('sha256', $xml);
        $parsed = xml_preview::parse($xml);
        $tags = self::moodle_check($context, $category, $xml, count($parsed['questions']));
        $rootpath = self::category_path($category, $contextid);
        $mappings = [];
        $categories = [];
        foreach ($parsed['categories'] as $path) {
            if ($path !== $rootpath && !str_starts_with($path, $rootpath . '/')) {
                throw new \moodle_exception('xmlscope', 'local_bosonanceqbank');
            }
            $targetid = self::resolve_path($contextid, $category, $rootpath, $path);
            if (!$targetid) {
                require_capability('moodle/question:managecategory', $context);
            }
            $mappings[$path] = $targetid;
            $categories[] = ['path' => $path, 'categoryid' => $targetid, 'action' => $targetid ? 'reuse' : 'create'];
        }

        $warnings = [];
        $previous = $DB->get_records(self::IMPORTS, ['contextid' => $contextid, 'sha256' => $sha256], 'id ASC',
            'id, categoryid, questionids');
        $exact = array_filter($previous, static fn($r) => (int)$r->categoryid === $categoryid);
        if ($exact) {
            $ids = [];
            foreach ($exact as $record) {
                $ids = array_merge($ids, array_map('intval', json_decode($record->questionids, true) ?: []));
            }
            $warnings[] = ['code' => 'exact_repeat', 'index' => 0, 'matchcount' => count($exact),
                'questionids' => array_slice($ids, 0, 20)];
        } else if ($previous) {
            $warnings[] = ['code' => 'file_imported_elsewhere', 'index' => 0, 'matchcount' => count($previous),
                'questionids' => []];
        }
        $seen = [];
        foreach ($parsed['questions'] as $index => $question) {
            $destination = $question['path'] === '' ? $categoryid : $mappings[$question['path']];
            $path = $question['path'] === '' ? $rootpath : $question['path'];
            $key = json_encode([$path, $question['name'], $question['qtype']], JSON_THROW_ON_ERROR);
            if (isset($seen[$key])) {
                $warnings[] = ['code' => 'duplicate_in_file', 'index' => $index + 1, 'matchcount' => 1, 'questionids' => []];
            }
            $seen[$key] = true;
            if (!$destination) {
                continue;
            }
            $sql = ' FROM {question} q JOIN {question_versions} qv ON qv.questionid = q.id
                     JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                    WHERE qbe.questioncategoryid = :categoryid AND q.name = :name AND q.qtype = :qtype';
            $matchparams = ['categoryid' => $destination, 'name' => $question['name'], 'qtype' => $question['qtype']];
            $count = $DB->count_records_sql('SELECT COUNT(*)' . $sql, $matchparams);
            if ($count) {
                $records = $DB->get_records_sql('SELECT q.id' . $sql . ' ORDER BY q.id ASC', $matchparams, 0, 20);
                $warnings[] = [
                    'code' => 'possible_existing_duplicate', 'index' => $index + 1,
                    'matchcount' => $count, 'questionids' => array_map('intval', array_keys($records)),
                ];
            }
        }
        return [
            'sha256' => $sha256,
            'questioncount' => count($parsed['questions']),
            'questions' => $parsed['questions'],
            'categories' => $categories,
            'tags' => $tags,
            'warnings' => $warnings,
            'exactrepeat' => (bool)$exact,
            'statehash' => self::state_hash($categoryid),
        ];
    }

    /**
     * Run Moodle's own XML reader and grade matching without saving questions.
     */
    private static function moodle_check(\context $context, \stdClass $category, string $xml, int $expected): array {
        $format = self::format($context, $category);
        $level = ob_get_level();
        ob_start();
        try {
            $questions = $format->readquestions([$xml]);
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
        if (!is_array($questions) || $format->importerrors > 0) {
            throw new \moodle_exception('xmlmoodle', 'local_bosonanceqbank');
        }
        $real = array_filter($questions, static fn($q) => is_object($q) && ($q->qtype ?? '') !== 'category');
        if (count($real) !== $expected) {
            throw new \moodle_exception('xmlmoodle', 'local_bosonanceqbank');
        }
        $options = \question_bank::fraction_options_full();
        $names = [];
        foreach ($real as $question) {
            $names = array_merge($names, $question->coursetags ?? [], $question->tags ?? []);
            foreach (is_array($question->fraction ?? null) ? $question->fraction : [] as $fraction) {
                if (match_grade_options($options, $fraction, 'error') === false) {
                    throw new \moodle_exception('xmlgrade', 'local_bosonanceqbank');
                }
            }
        }
        $names = array_values(array_filter(\core_tag_tag::normalize($names, false), static fn($name) => $name !== ''));
        $collection = \core_tag_area::get_collection('core_question', 'question');
        $tags = \core_tag_tag::get_by_name_bulk($collection, $names, 'id, name, rawname, tagcollid, isstandard');
        ksort($tags);
        $result = [];
        $rawnames = array_map('strval', array_flip(\core_tag_tag::normalize($names)));
        foreach ($tags as $name => $tag) {
            if (!$tag && $rawnames[$name] === '0') {
                throw new \moodle_exception('xmltags', 'local_bosonanceqbank');
            }
            $result[] = $tag ? self::tag_details($tag) : [
                'id' => 0, 'name' => $rawnames[$name], 'isstandard' => false,
                'collectionid' => (int)$collection, 'action' => 'create',
            ];
        }
        return $result;
    }

    private static function tag_details(\core_tag_tag $tag): array {
        return [
            'id' => (int)$tag->id, 'name' => $tag->rawname, 'isstandard' => (bool)$tag->isstandard,
            'collectionid' => (int)$tag->tagcollid, 'action' => 'reuse',
        ];
    }

    /** Create only previewed missing tags, in the same transaction as the questions. */
    private static function prepare_tags(array $planned): array {
        if (!$planned) {
            return [];
        }
        $collection = $planned[0]['collectionid'];
        $resolved = \core_tag_tag::get_by_name_bulk($collection, array_column($planned, 'name'),
            'id, name, rawname, tagcollid, isstandard');
        $missing = [];
        foreach ($planned as $item) {
            $name = \core_text::strtolower($item['name']);
            $tag = $resolved[$name] ?? null;
            if ($item['action'] === 'create') {
                if ($tag) {
                    throw new \moodle_exception('previewstale', 'local_bosonanceqbank');
                }
                $missing[] = $item['name'];
            } else if (!$tag || self::tag_details($tag) !== $item) {
                throw new \moodle_exception('previewstale', 'local_bosonanceqbank');
            }
        }
        if ($missing) {
            $resolved = array_replace($resolved, \core_tag_tag::create_if_missing($collection, $missing));
        }
        $ids = [];
        foreach ($planned as $item) {
            $tag = $resolved[\core_text::strtolower($item['name'])] ?? null;
            if (!$tag || (int)$tag->id < 1) {
                throw new \moodle_exception('importfailed', 'local_bosonanceqbank', '', 'tag creation failed');
            }
            $actual = self::tag_details($tag);
            if ($item['action'] === 'create') {
                $actual['id'] = 0;
                $actual['action'] = 'create';
            }
            if ($actual !== $item) {
                throw new \moodle_exception('importfailed', 'local_bosonanceqbank', '', 'unexpected tag metadata');
            }
            $ids[] = (int)$tag->id;
        }
        return $ids;
    }

    private static function format(\context $context, \stdClass $category): moodle_xml_format {
        $cm = get_coursemodule_from_id(null, $context->instanceid, 0, false, MUST_EXIST);
        $format = new moodle_xml_format();
        $format->setCategory($category);
        $format->setContexts([$context]);
        $format->setCourse(get_course($cm->course));
        $format->setMatchgrades('error');
        $format->setCatfromfile(true);
        $format->setContextfromfile(false);
        $format->setStoponerror(true);
        $format->set_display_progress(false);
        return $format;
    }

    private static function draft_file(int $draftitemid, string $filename): \stored_file {
        global $USER;

        if ($draftitemid < 1 || $filename === '' || $filename === '.'
                || clean_param($filename, PARAM_FILE) !== $filename) {
            throw new \invalid_parameter_exception(get_string('invalidfile', 'local_bosonanceqbank'));
        }
        $file = get_file_storage()->get_file(
            \context_user::instance($USER->id)->id, 'user', 'draft', $draftitemid, '/', $filename
        );
        if (!$file || $file->is_directory() || (int)$file->get_userid() !== (int)$USER->id) {
            throw new \moodle_exception('invalidfile', 'local_bosonanceqbank');
        }
        if ($file->get_filesize() > xml_preview::MAX_BYTES) {
            throw new \moodle_exception('xmllimit', 'local_bosonanceqbank');
        }
        return $file;
    }

    /**
     * Existing category ID for a path inside the root's subtree, or zero if any segment is missing.
     */
    private static function resolve_path(int $contextid, \stdClass $root, string $rootpath, string $path): int {
        global $DB;

        $targetid = (int)$root->id;
        foreach (array_filter(explode('/', substr($path, strlen($rootpath))), 'strlen') as $name) {
            $matches = $DB->get_records('question_categories', [
                'contextid' => $contextid, 'parent' => $targetid, 'name' => $name,
            ], 'id ASC', 'id', 0, 2);
            if (count($matches) > 1) {
                throw new \moodle_exception('xmlscope', 'local_bosonanceqbank');
            }
            if (!$matches) {
                return 0;
            }
            $targetid = (int)reset($matches)->id;
        }
        return $targetid;
    }

    private static function category_path(\stdClass $category, int $contextid): string {
        $segments = [];
        $visited = [];
        while ($category->parent) {
            if (isset($visited[$category->id])) {
                throw new \moodle_exception('xmlscope', 'local_bosonanceqbank');
            }
            $visited[$category->id] = true;
            array_unshift($segments, $category->name);
            $category = access::category($contextid, (int)$category->parent);
        }
        return '$module$/top' . ($segments ? '/' . implode('/', $segments) : '');
    }

    private static function entry_count(int $categoryid): int {
        global $DB;

        [$in, $params] = $DB->get_in_or_equal(array_values(question_categorylist($categoryid)));
        return $DB->count_records_select('question_bank_entries', "questioncategoryid $in", $params);
    }

    /**
     * Fingerprint of the destination subtree: categories plus entry counts and newest entry IDs.
     */
    private static function state_hash(int $categoryid): string {
        global $DB;

        [$in, $params] = $DB->get_in_or_equal(array_values(question_categorylist($categoryid)), SQL_PARAMS_NAMED);
        $rows = $DB->get_records_sql(
            "SELECT qc.id, qc.parent, qc.name, COUNT(qbe.id) AS entries, COALESCE(MAX(qbe.id), 0) AS newest
               FROM {question_categories} qc
          LEFT JOIN {question_bank_entries} qbe ON qbe.questioncategoryid = qc.id
              WHERE qc.id $in
           GROUP BY qc.id, qc.parent, qc.name
           ORDER BY qc.id", $params);
        $state = array_map(static fn($r) => [(int)$r->id, (int)$r->parent, $r->name, (int)$r->entries, (int)$r->newest],
            array_values($rows));
        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    private static function warnings_hash(array $warnings): string {
        return hash('sha256', json_encode($warnings, JSON_THROW_ON_ERROR));
    }

    private static function fingerprint(array $analysis): string {
        return hash('sha256', json_encode([
            $analysis['sha256'], $analysis['questioncount'], $analysis['categories'],
            $analysis['warnings'], $analysis['statehash'], $analysis['tags'],
        ], JSON_THROW_ON_ERROR));
    }

    private static function plain(string $html): string {
        return \core_text::substr(trim(preg_replace('/\s+/', ' ', strip_tags($html))), 0, 500);
    }
}
