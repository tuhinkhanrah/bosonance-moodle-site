<?php
namespace local_bosonanceqbank;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use local_bosonanceqbank\external\change_category;
use local_bosonanceqbank\external\preview_category;

final class category_test extends \advanced_testcase {
    private function fixture(): array {
        global $CFG;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $CFG->local_bosonanceqbank_enablecategories = true;
        require_once($CFG->libdir . '/questionlib.php');
        $course = $this->getDataGenerator()->create_course();
        $bank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $context = \context_module::instance($bank->cmid);
        $top = question_get_top_category($context->id, true);
        return [$context, $top];
    }

    private function preview(\context $context, int $categoryid, string $action, string $name): array {
        return external_api::clean_returnvalue(preview_category::execute_returns(),
            preview_category::execute($context->id, $categoryid, $action, $name));
    }

    private function apply(array $preview, bool $exclusive = true): array {
        return external_api::clean_returnvalue(change_category::execute_returns(),
            change_category::execute($preview['previewtoken'], $exclusive));
    }

    private function assert_error(string $code, callable $callback): void {
        try {
            $callback();
        } catch (\moodle_exception $e) {
            $this->assertSame($code, $e->errorcode);
            return;
        }
        $this->fail("Expected $code");
    }

    public function test_create_preview_confirmation_audit_and_replay(): void {
        global $DB;
        [$context, $top] = $this->fixture();
        $before = $DB->get_records('question_categories', ['contextid' => $context->id]);
        $sink = $this->redirectEvents();
        $preview = $this->preview($context, $top->id, 'create', 'Disposable category');
        $this->assertEquals($before, $DB->get_records('question_categories', ['contextid' => $context->id]));
        $this->assert_error('invalidparameter', fn() => $this->apply($preview, false));
        $result = $this->apply($preview);
        $created = $DB->get_record('question_categories', ['id' => $result['resultcategoryid']], '*', MUST_EXIST);
        $this->assertSame('Disposable category', $created->name);
        $this->assertEquals($top->id, $created->parent);
        $this->assertEquals($context->id, $created->contextid);
        $this->assertSame('', $created->info);
        $this->assertEquals(FORMAT_HTML, $created->infoformat);
        $this->assertNull($created->idnumber);
        $this->assertCount(count($before) + 1, $DB->get_records('question_categories', ['contextid' => $context->id]));
        foreach ($before as $id => $category) {
            $this->assertEquals($category, $DB->get_record('question_categories', ['id' => $id]));
        }
        $this->assertGreaterThan(0, $DB->get_field('local_bosonanceqbank_category', 'timeused',
            ['id' => $result['categoryauditid']]));
        $this->assert_error('categorypreviewused', fn() => $this->apply($preview));
        $this->assertCount(1, array_filter($sink->get_events(),
            fn($event) => $event instanceof \core\event\question_category_created));
    }

    public function test_rename_preserves_metadata_and_can_be_reversed(): void {
        global $DB;
        [$context, $top] = $this->fixture();
        $manager = new \core_question\category_manager();
        $id = $manager->add_category($top->id . ',' . $context->id, 'Original', '<p>Keep description</p>', FORMAT_HTML, 'keep');
        $original = $DB->get_record('question_categories', ['id' => $id], '*', MUST_EXIST);
        $sink = $this->redirectEvents();
        $preview = $this->preview($context, $id, 'rename', 'Renamed');
        $this->assertSame('Original', $preview['oldname']);
        $this->assertEquals($original, $DB->get_record('question_categories', ['id' => $id]));
        $result = $this->apply($preview);
        $this->assertSame($id, $result['resultcategoryid']);
        $expected = clone $original;
        $expected->name = 'Renamed';
        $this->assertEquals($expected, $DB->get_record('question_categories', ['id' => $id]));
        $this->apply($this->preview($context, $id, 'rename', 'Original'));
        $this->assertEquals($original, $DB->get_record('question_categories', ['id' => $id]));
        $this->assertCount(2, array_filter($sink->get_events(),
            fn($event) => $event instanceof \core\event\question_category_updated));
    }

    public function test_sibling_and_category_changes_invalidate_previews(): void {
        global $DB;
        [$context, $top] = $this->fixture();
        $preview = $this->preview($context, $top->id, 'create', 'Planned');
        $manager = new \core_question\category_manager();
        $id = $manager->add_category($top->id . ',' . $context->id, 'Other', '', FORMAT_HTML);
        $this->assert_error('categorystale', fn() => $this->apply($preview));
        $rename = $this->preview($context, $id, 'rename', 'New');
        $manager->update_category($id, '', 'Changed');
        $this->assert_error('categorystale', fn() => $this->apply($rename));
        $this->assertFalse($DB->record_exists('question_categories', ['name' => 'Planned', 'contextid' => $context->id]));
        $this->assertEquals(0, $DB->count_records_select('local_bosonanceqbank_category', 'timeused > 0'));
    }

    public function test_invalid_names_duplicates_and_nonempty_renames_are_blocked(): void {
        [$context, $top] = $this->fixture();
        foreach (['', '0', ' padded ', 'a/b', 'a\\b', "<b>name</b>", "a\nb", str_repeat('a', 256)] as $name) {
            $this->assert_error('invalidparameter', fn() => $this->preview($context, $top->id, 'create', $name));
        }
        $this->assert_error('invalidparameter', fn() => $this->preview($context, $top->id, 'delete', 'Name'));
        $this->assert_error('categoryunsupported', fn() => $this->preview($context, $top->id, 'rename', 'Name'));
        $manager = new \core_question\category_manager();
        $id = $manager->add_category($top->id . ',' . $context->id, 'Existing', '', FORMAT_HTML);
        $this->assert_error('categorynameconflict', fn() => $this->preview($context, $top->id, 'create', 'existing'));
        $this->assert_error('categorynameconflict', fn() => $this->preview($context, $id, 'rename', 'Existing'));
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $generator->create_question('multichoice', null, ['category' => $id]);
        $this->assert_error('categoryunsupported', fn() => $this->preview($context, $id, 'rename', 'New'));
    }

    public function test_child_added_after_preview_blocks_rename(): void {
        global $DB;
        [$context, $top] = $this->fixture();
        $manager = new \core_question\category_manager();
        $id = $manager->add_category($top->id . ',' . $context->id, 'Leaf', '', FORMAT_HTML);
        $preview = $this->preview($context, $id, 'rename', 'New');
        $manager->add_category($id . ',' . $context->id, 'Child', '', FORMAT_HTML);
        $this->assert_error('categoryunsupported', fn() => $this->apply($preview));
        $this->assertSame('Leaf', $DB->get_field('question_categories', 'name', ['id' => $id]));
    }

    public function test_question_set_reference_added_after_preview_blocks_rename(): void {
        global $DB;
        [$context, $top] = $this->fixture();
        $manager = new \core_question\category_manager();
        $id = $manager->add_category($top->id . ',' . $context->id, 'Leaf', '', FORMAT_HTML);
        $preview = $this->preview($context, $id, 'rename', 'New');
        $DB->insert_record('question_set_references', (object)[
            'usingcontextid' => $context->id, 'component' => 'mod_quiz', 'questionarea' => 'slot',
            'itemid' => 999, 'questionscontextid' => $context->id, 'filtercondition' => '{}',
        ]);
        $this->assert_error('categoryunsupported', fn() => $this->apply($preview));
        $this->assertSame('Leaf', $DB->get_field('question_categories', 'name', ['id' => $id]));
        $this->assertEquals(0, $DB->get_field('local_bosonanceqbank_category', 'timeused', ['categoryid' => $id]));
    }

    public function test_privacy_deletes_unused_previews_and_anonymises_audits(): void {
        global $DB, $USER;
        [$context, $top] = $this->fixture();
        $used = $this->apply($this->preview($context, $top->id, 'create', 'Created'));
        $this->preview($context, $top->id, 'create', 'Unused');
        $provider = \local_bosonanceqbank\privacy\provider::class;
        $contextids = array_map('intval', $provider::get_contexts_for_userid($USER->id)->get_contextids());
        $this->assertContains((int)$context->id, $contextids);
        $provider::delete_data_for_user(new \core_privacy\local\request\approved_contextlist(
            $USER, 'local_bosonanceqbank', [$context->id]));
        $records = $DB->get_records('local_bosonanceqbank_category', ['contextid' => $context->id]);
        $this->assertCount(1, $records);
        $audit = $records[$used['categoryauditid']];
        $this->assertEquals(0, $audit->userid);
        $this->assertGreaterThan(0, $audit->timeused);
        $this->assertTrue($DB->record_exists('question_categories', ['id' => $used['resultcategoryid']]));
    }

    public function test_context_user_expiry_and_feature_checks(): void {
        global $CFG, $DB;
        [$context, $top] = $this->fixture();
        $otherbank = $this->getDataGenerator()->create_module('qbank', ['course' => $context->get_course_context()->instanceid]);
        $other = \context_module::instance($otherbank->cmid);
        $this->assert_error('wrongcontext', fn() => $this->preview($other, $top->id, 'create', 'New'));
        $preview = $this->preview($context, $top->id, 'create', 'New');
        $DB->set_field('local_bosonanceqbank_category', 'timeexpires', time() - 1, []);
        $this->assert_error('categorypreviewexpired', fn() => $this->apply($preview));
        $this->assert_error('categorypreviewinvalid', fn() => change_category::execute(str_repeat('a', 64), true));
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assert_error('categorypreviewinvalid', fn() => $this->apply($preview));
        $this->assert_error('adminrequired', fn() => $this->preview($context, $top->id, 'create', 'New'));
        $this->setAdminUser();
        unset($CFG->local_bosonanceqbank_enablecategories);
        $this->assert_error('categorydisabled', fn() => $this->apply($preview));
        $this->assert_error('categorydisabled', fn() => $this->preview($context, $top->id, 'create', 'New'));
    }
}
