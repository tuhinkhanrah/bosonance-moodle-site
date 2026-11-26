<?php
namespace local_bosonanceqbank;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use local_bosonanceqbank\external\export_xml;
use local_bosonanceqbank\external\get_question;
use local_bosonanceqbank\external\import_xml;
use local_bosonanceqbank\external\list_categories;
use local_bosonanceqbank\external\list_questions;
use local_bosonanceqbank\external\preview_xml;

final class external_test extends \advanced_testcase {
    private function question_xml(): string {
        return '<question type="multichoice"><name><text>Example</text></name>'
            . '<questiontext><text>Question?</text></questiontext><single>true</single>'
            . '<answer fraction="100"><text>Yes</text></answer>'
            . '<answer fraction="0"><text>No</text></answer></question>';
    }

    private function bank_fixture(): array {
        $course = $this->getDataGenerator()->create_course();
        $bank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $context = \context_module::instance($bank->cmid);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $top = question_get_top_category($context->id, true);
        $category = $generator->create_question_category([
            'contextid' => $context->id, 'parent' => $top->id, 'name' => 'bank',
        ]);
        $question = $generator->create_question('multichoice', null, ['category' => $category->id, 'name' => 'Example']);
        return [$context, $category, $question];
    }

    private function upload(string $xml, int $itemid = 123): void {
        global $USER;

        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id, 'component' => 'user',
            'filearea' => 'draft', 'itemid' => $itemid, 'filepath' => '/', 'filename' => 'bank.xml',
            'userid' => $USER->id,
        ], $xml);
    }

    public function test_listing_details_and_export_preserve_questions(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        require_once($CFG->libdir . '/questionlib.php');
        [$context, $category, $question] = $this->bank_fixture();
        $before = $DB->get_record('question', ['id' => $question->id], '*', MUST_EXIST);
        $categories = external_api::clean_returnvalue(list_categories::execute_returns(), list_categories::execute($context->id));
        $this->assertContains((int)$category->id, array_column($categories['items'], 'categoryid'));
        $list = external_api::clean_returnvalue(list_questions::execute_returns(),
            list_questions::execute($context->id, $category->id, 0, 1, 'ready', 'multichoice', 'Example'));
        $this->assertSame(1, $list['total']);
        $this->assertSame((int)$question->id, $list['items'][0]['questionid']);
        $details = external_api::clean_returnvalue(get_question::execute_returns(), get_question::execute($context->id, $question->id));
        $this->assertArrayNotHasKey('content', $details);
        $this->assertFalse($details['inuse']);
        $this->assertSame(1, $details['versiontotal']);
        $content = get_question::execute($context->id, $question->id, true);
        $this->assertSame($before->questiontext, $content['content']['questiontext']);
        $export = external_api::clean_returnvalue(export_xml::execute_returns(), export_xml::execute($context->id, [$question->id]));
        $this->assertSame(1, $export['questioncount']);
        $this->assertStringContainsString('$module$/top/bank', $export['xml']);
        $this->assertSame(hash('sha256', $export['xml']), $export['sha256']);
        $this->assertEquals($before, $DB->get_record('question', ['id' => $question->id], '*', MUST_EXIST));
    }

    public function test_preview_reports_duplicates_and_missing_categories_without_writes(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        require_once($CFG->libdir . '/questionlib.php');
        [$context, $category, $question] = $this->bank_fixture();
        $record = $this->question_xml();
        $this->upload('<quiz>' . $record . $record
            . '<question type="category"><category><text>$module$/top/bank/new</text></category></question>'
            . $record . '</quiz>');
        $tables = ['question', 'question_versions', 'question_categories', 'question_bank_entries', 'files'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = $DB->count_records($table);
        }
        $sink = $this->redirectEvents();
        $preview = $this->preview($context, $category);
        $this->assertTrue($preview['canapply']);
        $this->assertFalse($preview['requiresduplicateoverride']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $preview['previewtoken']);
        $this->assertSame(3, $preview['questioncount']);
        $this->assertSame('create', $preview['categories'][0]['action']);
        $codes = array_column($preview['warnings'], 'code');
        $this->assertContains('duplicate_in_file', $codes);
        $existing = $preview['warnings'][array_search('possible_existing_duplicate', $codes, true)];
        $this->assertContains((int)$question->id, $existing['questionids']);
        foreach ($tables as $table) {
            $this->assertSame($before[$table], $DB->count_records($table));
        }
        $this->assertSame([], $sink->get_events());
        $stored = $DB->get_record('local_bosonanceqbank_preview', [], '*', MUST_EXIST);
        $this->assertSame(hash('sha256', $preview['previewtoken']), $stored->tokenhash);
        $this->assertSame(0, (int)$stored->timeused);
    }

    private function preview(\context $context, \stdClass $category, int $itemid = 123): array {
        return external_api::clean_returnvalue(preview_xml::execute_returns(),
            preview_xml::execute($context->id, $category->id, $itemid, 'bank.xml'));
    }

    private function import(array $preview, ?string $hash = null, bool $allowduplicate = false): array {
        return external_api::clean_returnvalue(import_xml::execute_returns(),
            import_xml::execute($preview['previewtoken'], $hash ?? $preview['warningshash'], $allowduplicate));
    }

    private function assert_error(string $code, callable $callback): void {
        try {
            $callback();
            $this->fail("Expected $code");
        } catch (\moodle_exception $e) {
            $this->assertSame($code, $e->errorcode);
        }
    }

    private function import_fixture(): array {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();
        require_once($CFG->libdir . '/questionlib.php');
        [$context, $category] = $this->bank_fixture();
        $this->upload('<quiz><question type="category"><category><text>$module$/top/bank/imported</text></category></question>'
            . str_replace('Example', 'Imported', $this->question_xml()) . '</quiz>');
        return [$context, $category];
    }

    public function test_import_requires_acknowledgement_and_is_single_use(): void {
        global $DB;

        [$context, $category] = $this->import_fixture();
        $preview = $this->preview($context, $category);
        $this->assert_error('acknowledgement', fn() => $this->import($preview, str_repeat('0', 64)));
        $this->assertSame(0, $DB->count_records('local_bosonanceqbank_import'));

        $sink = $this->redirectEvents();
        $result = $this->import($preview);
        $this->assertSame(1, $result['questioncount']);
        $this->assertFalse($result['duplicateoverride']);
        $this->assertSame('create', $result['categories'][0]['action']);
        $created = $DB->get_record('question_categories',
            ['contextid' => $context->id, 'parent' => $category->id, 'name' => 'imported'], '*', MUST_EXIST);
        $this->assertSame((int)$created->id, $result['questions'][0]['categoryid']);
        $this->assertSame('Imported', $DB->get_field('question', 'name', ['id' => $result['questions'][0]['questionid']]));
        $events = array_map(static fn($e) => get_class($e), $sink->get_events());
        $this->assertContains(\core\event\questions_imported::class, $events);
        $audit = $DB->get_record('local_bosonanceqbank_import', ['id' => $result['importid']], '*', MUST_EXIST);
        $this->assertSame([$result['questions'][0]['questionid']], json_decode($audit->questionids, true));

        $this->assert_error('previewused', fn() => $this->import($preview));
        $this->assertSame(1, $DB->count_records('local_bosonanceqbank_import'));
    }

    public function test_xml_previews_missing_tags_then_creates_and_assigns_them_on_import(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        require_once($CFG->libdir . '/questionlib.php');
        [$context, $category] = $this->bank_fixture();
        $collection = \core_tag_area::get_collection('core_question', 'question');
        $existing = $this->getDataGenerator()->create_tag([
            'rawname' => 'Existing import tag', 'isstandard' => 1, 'tagcollid' => $collection,
        ]);
        $original = $DB->get_record('tag', ['id' => $existing->id], '*', MUST_EXIST);
        $before = $DB->count_records('tag');
        $questionsbefore = $DB->get_records('question');
        $first = str_replace('</question>',
            '<tags><tag><text>New import tag</text></tag></tags></question>', $this->question_xml());
        $tagged = str_replace('</question>',
            '<tags><tag><text>NEW IMPORT TAG</text></tag><tag><text>New second tag</text></tag></tags>'
            . '<coursetags><tag><text>Existing import tag</text></tag></coursetags></question>', $this->question_xml());
        $this->upload('<quiz>' . $first . $tagged . '</quiz>');
        $preview = $this->preview($context, $category);
        $this->assertEquals($before, $DB->count_records('tag'));
        $this->assertEquals($questionsbefore, $DB->get_records('question'));
        $created = array_values(array_filter($preview['tags'], fn($tag) => $tag['action'] === 'create'));
        $this->assertCount(2, $created);
        foreach ($created as $tag) {
            $this->assertSame(0, $tag['id']);
            $this->assertFalse($tag['isstandard']);
            $this->assertSame((int)$collection, $tag['collectionid']);
        }
        $result = $this->import($preview);
        $this->assertEquals($before + 2, $DB->count_records('tag'));
        $new = $DB->get_record('tag', ['tagcollid' => $collection, 'name' => 'new import tag'], '*', MUST_EXIST);
        $second = $DB->get_record('tag', ['tagcollid' => $collection, 'name' => 'new second tag'], '*', MUST_EXIST);
        $this->assertSame(0, (int)$new->isstandard);
        $this->assertSame(0, (int)$second->isstandard);
        $this->assertEquals($original, $DB->get_record('tag', ['id' => $existing->id]));
        $firstids = array_map('intval', array_keys(\core_tag_tag::get_item_tags_array(
            'core_question', 'question', $result['questions'][0]['questionid'])));
        $secondids = array_map('intval', array_keys(\core_tag_tag::get_item_tags_array(
            'core_question', 'question', $result['questions'][1]['questionid'])));
        $this->assertSame([(int)$new->id], $firstids);
        $this->assertEqualsCanonicalizing([(int)$new->id, (int)$second->id, (int)$existing->id], $secondids);
        $this->assert_error('previewused', fn() => $this->import($preview));
        $this->assertEquals($before + 2, $DB->count_records('tag'));
    }

    public function test_missing_tag_created_after_preview_rejects_stale_import(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        require_once($CFG->libdir . '/questionlib.php');
        [$context, $category] = $this->bank_fixture();
        $this->upload('<quiz>' . str_replace('</question>',
            '<tags><tag><text>Planned tag</text></tag></tags></question>', $this->question_xml()) . '</quiz>');
        $preview = $this->preview($context, $category);
        $before = $DB->get_records('question');
        $this->getDataGenerator()->create_tag([
            'rawname' => 'Planned tag', 'tagcollid' => \core_tag_area::get_collection('core_question', 'question'),
        ]);
        $this->assert_error('previewstale', fn() => $this->import($preview));
        $this->assertEquals($before, $DB->get_records('question'));
        $this->assertEquals(0, $DB->count_records('local_bosonanceqbank_import'));
        $this->assertSame(0, (int)$DB->get_field('local_bosonanceqbank_preview', 'timeused', []));
    }

    public function test_failed_import_rolls_back_new_tags_and_questions(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        require_once($CFG->libdir . '/questionlib.php');
        [$context, $category] = $this->bank_fixture();
        $xml = '<quiz>' . str_replace('</question>',
            '<tags><tag><text>Rollback tag</text></tag></tags></question>', $this->question_xml()) . '</quiz>';
        $this->upload($xml);
        $preview = $this->preview($context, $category);
        $record = $DB->get_record('local_bosonanceqbank_preview',
            ['tokenhash' => hash('sha256', $preview['previewtoken'])], '*', MUST_EXIST);
        $analysis = (new \ReflectionMethod(importer::class, 'analyse'))->invoke(null, $context, $category, $xml);
        // Force a post-import count mismatch after the native helpers have created tags and questions.
        $analysis['questioncount']++;
        $before = $DB->get_records('tag');
        $questionsbefore = $DB->get_records('question');
        $this->assert_error('importfailed', fn() => (new \ReflectionMethod(importer::class, 'import'))
            ->invoke(null, $context, $category, $record, $xml, $analysis, false));
        $this->assertEquals($before, $DB->get_records('tag'));
        $this->assertEquals($questionsbefore, $DB->get_records('question'));
        $this->assertEquals(0, $DB->count_records('local_bosonanceqbank_import'));
        $this->assertSame(0, (int)$DB->get_field('local_bosonanceqbank_preview', 'timeused', []));
    }

    public function test_xml_import_reuses_and_previews_existing_tags(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        require_once($CFG->libdir . '/questionlib.php');
        [$context, $category] = $this->bank_fixture();
        $collection = \core_tag_area::get_collection('core_question', 'question');
        $tag = $this->getDataGenerator()->create_tag(['rawname' => 'Existing import tag', 'tagcollid' => $collection]);
        $before = $DB->count_records('tag');
        $tagged = str_replace('</question>',
            '<coursetags><tag><text>Existing import tag</text></tag></coursetags></question>', $this->question_xml());
        $this->upload('<quiz>' . $this->question_xml() . $tagged . '</quiz>');
        $preview = $this->preview($context, $category);
        $this->assertSame([(int)$tag->id], array_column($preview['tags'], 'id'));
        $this->assertSame('reuse', $preview['tags'][0]['action']);
        $result = $this->import($preview);
        $this->assertEquals($before, $DB->count_records('tag'));
        $first = $result['questions'][0]['questionid'];
        $second = $result['questions'][1]['questionid'];
        $this->assertCount(0, \core_tag_tag::get_item_tags('core_question', 'question', $first));
        $this->assertSame([(int)$tag->id], array_map('intval', array_keys(
            \core_tag_tag::get_item_tags_array('core_question', 'question', $second))));
    }

    public function test_tag_metadata_change_after_preview_rejects_import(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        require_once($CFG->libdir . '/questionlib.php');
        [$context, $category] = $this->bank_fixture();
        $tag = $this->getDataGenerator()->create_tag([
            'rawname' => 'Existing import tag', 'isstandard' => 1,
            'tagcollid' => \core_tag_area::get_collection('core_question', 'question'),
        ]);
        $this->upload('<quiz>' . str_replace('</question>',
            '<tags><tag><text>Existing import tag</text></tag></tags></question>', $this->question_xml()) . '</quiz>');
        $preview = $this->preview($context, $category);
        $DB->set_field('tag', 'isstandard', 0, ['id' => $tag->id]);
        $this->assert_error('previewstale', fn() => $this->import($preview));
        $this->assertEquals(0, $DB->count_records('local_bosonanceqbank_import'));
    }

    public function test_privacy_erasure_scrubs_filenames_and_preserves_repeat_signatures(): void {
        global $CFG, $DB, $USER;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        require_once($CFG->libdir . '/questionlib.php');
        [$context, $category] = $this->bank_fixture();
        $this->upload('<quiz>' . $this->question_xml() . '</quiz>');
        $result = $this->import($this->preview($context, $category));
        $original = $DB->get_record('local_bosonanceqbank_import', ['id' => $result['importid']], '*', MUST_EXIST);
        $other = clone $original;
        unset($other->id);
        $other->userid = $this->getDataGenerator()->create_user()->id;
        $other->filename = 'other-user.xml';
        $otherid = $DB->insert_record('local_bosonanceqbank_import', $other);
        $provider = \local_bosonanceqbank\privacy\provider::class;
        $provider::delete_data_for_user(new \core_privacy\local\request\approved_contextlist(
            $USER, 'local_bosonanceqbank', [$context->id]));
        $erased = $DB->get_record('local_bosonanceqbank_import', ['id' => $original->id], '*', MUST_EXIST);
        $this->assertSame('', $erased->filename);
        $this->assertEquals(0, $erased->userid);
        $this->assertSame($original->sha256, $erased->sha256);
        $this->assertSame($original->questionids, $erased->questionids);
        $this->assertSame('other-user.xml', $DB->get_field('local_bosonanceqbank_import', 'filename', ['id' => $otherid]));
        $this->assertTrue($this->preview($context, $category)['requiresduplicateoverride']);
        $provider::delete_data_for_all_users_in_context($context);
        $this->assertSame('', $DB->get_field('local_bosonanceqbank_import', 'filename', ['id' => $otherid]));
        $this->assertEquals(0, $DB->get_field('local_bosonanceqbank_import', 'userid', ['id' => $otherid]));
    }

    public function test_exact_repeat_requires_explicit_override(): void {
        global $DB;

        [$context, $category] = $this->import_fixture();
        $first = $this->import($this->preview($context, $category));
        $repeat = $this->preview($context, $category);
        $this->assertTrue($repeat['requiresduplicateoverride']);
        $this->assertSame('exact_repeat', $repeat['warnings'][0]['code']);
        $this->assertSame([$first['questions'][0]['questionid']], $repeat['warnings'][0]['questionids']);
        $this->assertContains('possible_existing_duplicate', array_column($repeat['warnings'], 'code'));
        $this->assertSame('reuse', $repeat['categories'][0]['action']);
        $this->assert_error('duplicateimport', fn() => $this->import($repeat));
        $this->assertSame(1, $DB->count_records('local_bosonanceqbank_import'));

        $second = $this->import($repeat, null, true);
        $this->assertTrue($second['duplicateoverride']);
        $this->assertSame($first['questions'][0]['categoryid'], $second['questions'][0]['categoryid']);
        $this->assertSame(2, $DB->count_records('question_bank_entries',
            ['questioncategoryid' => $first['questions'][0]['categoryid']]));
    }

    public function test_bank_change_after_preview_is_stale(): void {
        global $DB;

        [$context, $category] = $this->import_fixture();
        $preview = $this->preview($context, $category);
        $this->getDataGenerator()->get_plugin_generator('core_question')
            ->create_question('multichoice', null, ['category' => $category->id, 'name' => 'Later']);
        $this->assert_error('previewstale', fn() => $this->import($preview));
        $this->assertSame(0, $DB->count_records('local_bosonanceqbank_import'));
    }

    public function test_expired_and_foreign_tokens_are_rejected(): void {
        global $DB;

        [$context, $category] = $this->import_fixture();
        $preview = $this->preview($context, $category);
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assert_error('previewinvalid', fn() => $this->import($preview));
        $this->setAdminUser();
        $DB->set_field('local_bosonanceqbank_preview', 'timeexpires', time() - 1, []);
        $this->assert_error('previewexpired', fn() => $this->import($preview));
        $this->assertSame(0, $DB->count_records('local_bosonanceqbank_import'));
    }

    public function test_failure_mid_import_rolls_back_everything(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        require_once($CFG->libdir . '/questionlib.php');
        [$context, $category] = $this->bank_fixture();
        $this->getDataGenerator()->get_plugin_generator('core_question')->create_question_category([
            'contextid' => $context->id, 'parent' => $category->parent, 'name' => 'other', 'idnumber' => 'taken',
        ]);
        $this->upload('<quiz>' . str_replace('Example', 'First', $this->question_xml())
            . '<question type="category"><category><text>$module$/top/bank/broken</text></category>'
            . '<idnumber>taken</idnumber></question>'
            . str_replace('Example', 'Second', $this->question_xml()) . '</quiz>');
        $tables = ['question', 'question_versions', 'question_categories', 'question_bank_entries',
            'local_bosonanceqbank_import'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = $DB->count_records($table);
        }
        $preview = $this->preview($context, $category);
        try {
            $this->import($preview);
            $this->fail('Import should fail on the duplicate category idnumber');
        } catch (\Throwable $e) {
            $this->assertFalse($DB->is_transaction_started());
        }
        foreach ($tables as $table) {
            $this->assertSame($before[$table], $DB->count_records($table), $table);
        }
        $this->assertSame(0, (int)$DB->get_field('local_bosonanceqbank_preview', 'timeused', []));
    }

    public function test_cross_context_question_is_rejected(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();
        require_once($CFG->libdir . '/questionlib.php');
        [$first] = $this->bank_fixture();
        [, , $question] = $this->bank_fixture();
        $this->expectException(\moodle_exception::class);
        get_question::execute($first->id, $question->id);
    }

    public function test_non_admin_is_rejected(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('adminrequired', 'local_bosonanceqbank'));
        list_categories::execute(1);
    }

    public function test_other_users_draft_is_not_read(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();
        require_once($CFG->libdir . '/questionlib.php');
        [$context, $category] = $this->bank_fixture();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->upload('<quiz>' . $this->question_xml() . '</quiz>');
        $this->setAdminUser();
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('invalidfile', 'local_bosonanceqbank'));
        preview_xml::execute($context->id, $category->id, 123, 'bank.xml');
    }

    public function test_outside_subtree_path_is_rejected(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();
        require_once($CFG->libdir . '/questionlib.php');
        [$context, $category] = $this->bank_fixture();
        $this->upload('<quiz><question type="category"><category><text>$module$/top/other</text></category></question>'
            . $this->question_xml() . '</quiz>');
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('xmlscope', 'local_bosonanceqbank'));
        preview_xml::execute($context->id, $category->id, 123, 'bank.xml');
    }

    public function test_page_limit_is_enforced(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();
        require_once($CFG->libdir . '/questionlib.php');
        [$context] = $this->bank_fixture();
        $this->expectException(\invalid_parameter_exception::class);
        list_categories::execute($context->id, 0, 101);
    }

    public function test_export_refuses_hidden_versions(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        require_once($CFG->libdir . '/questionlib.php');
        [$context, , $question] = $this->bank_fixture();
        $DB->set_field('question_versions', 'status', 'hidden', ['questionid' => $question->id]);
        $this->expectException(\moodle_exception::class);
        export_xml::execute($context->id, [$question->id]);
    }
}
