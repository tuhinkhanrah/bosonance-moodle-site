<?php
namespace local_bosonanceqbank;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use local_bosonanceqbank\external\delete_category;
use local_bosonanceqbank\external\delete_question;
use local_bosonanceqbank\external\preview_delete_category;
use local_bosonanceqbank\external\preview_delete_question;

final class deletion_test extends \advanced_testcase {
    private function fixture(): array {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $CFG->local_bosonanceqbank_enabledeletions = true;
        require_once($CFG->libdir . '/questionlib.php');
        $course = $this->getDataGenerator()->create_course();
        $bank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $context = \context_module::instance($bank->cmid);
        $top = question_get_top_category($context->id, true);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category(['contextid' => $context->id, 'parent' => $top->id]);
        $leaf = $generator->create_question_category(['contextid' => $context->id, 'parent' => $category->id]);
        $question = $generator->create_question('multichoice', null, ['category' => $category->id]);
        $version = $DB->get_record('question_versions', ['questionid' => $question->id], '*', MUST_EXIST);
        return [$context, $category, $leaf, $question, $version];
    }

    private function question_preview(\context $context, int $questionid, int $version = 1): array {
        return external_api::clean_returnvalue(preview_delete_question::execute_returns(),
            preview_delete_question::execute($context->id, $questionid, $version));
    }

    private function category_preview(\context $context, int $categoryid, bool $recursive = false,
            bool $deleteunusedquestions = false): array {
        return external_api::clean_returnvalue(preview_delete_category::execute_returns(),
            preview_delete_category::execute($context->id, $categoryid, $recursive, $deleteunusedquestions));
    }

    private function apply(array $preview, bool $exclusive = true): array {
        $class = $preview['kind'] === 'question' ? delete_question::class : delete_category::class;
        return external_api::clean_returnvalue($class::execute_returns(),
            $class::execute($preview['previewtoken'], $exclusive));
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

    public function test_all_versions_options_files_and_tags_are_deleted_without_global_tag_deletion(): void {
        global $CFG, $DB;
        [$context, $category, , $question, $version] = $this->fixture();
        $other = $this->getDataGenerator()->get_plugin_generator('core_question')
            ->create_question('multichoice', null, ['category' => $category->id]);
        $otherversion = $DB->get_record('question_versions', ['questionid' => $other->id], '*', MUST_EXIST);
        $DB->set_field('question_versions', 'version', 2, ['id' => $otherversion->id]);
        $DB->set_field('question_versions', 'questionbankentryid', $version->questionbankentryid, ['id' => $otherversion->id]);
        $DB->set_field('question_versions', 'status', 'draft', ['id' => $otherversion->id]);
        $DB->set_field('question_versions', 'status', 'hidden', ['id' => $version->id]);
        delete_question_bank_entry($otherversion->questionbankentryid);
        $CFG->usetags = true;
        \core_tag_tag::set_item_tags('core_question', 'question', $question->id, $context, ['Deletion QA']);
        $tags = $DB->get_records('tag');
        $answers = $DB->get_records('question_answers', ['question' => $question->id]);
        $storage = get_file_storage();
        $file = $storage->create_file_from_string([
            'contextid' => $context->id, 'component' => 'question', 'filearea' => 'questiontext',
            'itemid' => $question->id, 'filepath' => '/', 'filename' => 'question.txt',
        ], 'Question file');
        $answerfile = $storage->create_file_from_string([
            'contextid' => $context->id, 'component' => 'question', 'filearea' => 'answerfeedback',
            'itemid' => array_key_first($answers), 'filepath' => '/', 'filename' => 'feedback.txt',
        ], 'Answer feedback file');
        $hintid = $DB->insert_record('question_hints', (object)[
            'questionid' => $question->id, 'hint' => 'Hint', 'hintformat' => FORMAT_HTML,
            'shownumcorrect' => 0, 'clearwrong' => 0,
        ]);
        $hintfile = $storage->create_file_from_string([
            'contextid' => $context->id, 'component' => 'question', 'filearea' => 'hint',
            'itemid' => $hintid, 'filepath' => '/', 'filename' => 'hint.txt',
        ], 'Hint file');
        $preview = $this->question_preview($context, $other->id, 2);
        $this->assertSame(2, $preview['questioncount']);
        $this->assertTrue($DB->record_exists('question', ['id' => $question->id]));
        $this->assert_error('invalidparameter', fn() => $this->apply($preview, false));
        $sink = $this->redirectEvents();
        $result = $this->apply($preview);
        $this->assertTrue($result['deleted']);
        $this->assertFalse($DB->record_exists('question_bank_entries', ['id' => $version->questionbankentryid]));
        foreach ([$question, $other] as $deleted) {
            $this->assertFalse($DB->record_exists('question', ['id' => $deleted->id]));
            $this->assertFalse($DB->record_exists('question_versions', ['questionid' => $deleted->id]));
            $this->assertFalse($DB->record_exists('question_answers', ['question' => $deleted->id]));
            $this->assertFalse($DB->record_exists('question_hints', ['questionid' => $deleted->id]));
            $this->assertFalse($DB->record_exists('qtype_multichoice_options', ['questionid' => $deleted->id]));
            $this->assertFalse($DB->record_exists('tag_instance',
                ['component' => 'core_question', 'itemtype' => 'question', 'itemid' => $deleted->id]));
        }
        $this->assertFalse($DB->record_exists('files', ['id' => $file->get_id()]));
        $this->assertFalse($DB->record_exists('files', ['id' => $answerfile->get_id()]));
        $this->assertFalse($DB->record_exists('files', ['id' => $hintfile->get_id()]));
        $this->assertEquals($tags, $DB->get_records('tag'));
        $this->assertTrue($DB->record_exists('question_categories', ['id' => $category->id]));
        $this->assertCount(2, array_filter($sink->get_events(),
            fn($event) => $event instanceof \core\event\question_deleted));
        $this->assert_error('deletepreviewused', fn() => $this->apply($preview));
    }

    public function test_empty_leaf_deletion_preserves_parent_and_rejects_competing_preview(): void {
        global $DB;
        [$context, $category, $leaf] = $this->fixture();
        $before = $DB->get_records('question_categories', ['contextid' => $context->id]);
        $preview = $this->category_preview($context, $leaf->id);
        $competing = $this->category_preview($context, $leaf->id);
        $this->assertEquals($before, $DB->get_records('question_categories', ['contextid' => $context->id]));
        $sink = $this->redirectEvents();
        $this->assertTrue($this->apply($preview)['deleted']);
        unset($before[$leaf->id]);
        $this->assertEquals($before, $DB->get_records('question_categories', ['contextid' => $context->id]));
        $this->assert_error('deletepreviewused', fn() => $this->apply($preview));
        $this->assert_error('deletestale', fn() => $this->apply($competing));
        $this->assertCount(1, array_filter($sink->get_events(),
            fn($event) => $event instanceof \core\event\question_category_deleted));
    }

    public function test_recursive_empty_tree_deletes_bottom_up_with_one_name_free_audit(): void {
        global $DB;
        [$context, $category, $leaf] = $this->fixture();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $older = $generator->create_question_category(['contextid' => $context->id, 'parent' => $leaf->id]);
        $newer = $generator->create_question_category(['contextid' => $context->id, 'parent' => $leaf->id]);
        $DB->set_field('question_categories', 'parent', $newer->id, ['id' => $older->id]);
        $grandchild = $generator->create_question_category(['contextid' => $context->id, 'parent' => $older->id]);
        $before = $DB->get_records('question_categories', ['contextid' => $context->id]);
        $questionsbefore = $DB->get_records('question');
        $this->assert_error('deletecategoryoccupied', fn() => $this->category_preview($context, $leaf->id));
        $preview = $this->category_preview($context, $leaf->id, true);
        $competing = $this->category_preview($context, $leaf->id, true);
        $this->assertSame(4, $preview['categorycount']);
        $this->assertTrue($preview['recursive']);
        foreach ($preview['categories'] as $item) {
            $this->assertSame($before[$item['categoryid']]->name, $item['name']);
        }
        $this->assertEquals($before, $DB->get_records('question_categories', ['contextid' => $context->id]));
        $sink = $this->redirectEvents();
        $result = $this->apply($preview);
        $this->assertTrue($result['deleted']);
        $this->assertSame(4, $result['categorycount']);
        foreach ([$leaf, $older, $newer, $grandchild] as $deleted) {
            unset($before[$deleted->id]);
        }
        $this->assertEquals($before, $DB->get_records('question_categories', ['contextid' => $context->id]));
        $this->assertEquals($questionsbefore, $DB->get_records('question'));
        $this->assertCount(4, array_filter($sink->get_events(),
            fn($event) => $event instanceof \core\event\question_category_deleted));
        $this->assertSame(2, $DB->count_records('local_bosonanceqbank_delete'));
        $audit = $DB->get_record('local_bosonanceqbank_delete', ['id' => $result['deletionid']], '*', MUST_EXIST);
        $details = json_decode($audit->details, true, 512, JSON_THROW_ON_ERROR);
        foreach ($details['categories'] as $item) {
            $this->assertArrayNotHasKey('name', $item);
        }
        $this->assert_error('deletepreviewused', fn() => $this->apply($preview));
        $this->assert_error('deletestale', fn() => $this->apply($competing));
    }

    public function test_recursive_deletion_rejects_hidden_question_in_any_descendant(): void {
        global $DB;
        [$context, , $leaf] = $this->fixture();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $child = $generator->create_question_category(['contextid' => $context->id, 'parent' => $leaf->id]);
        $preview = $this->category_preview($context, $leaf->id, true);
        $question = $generator->create_question('multichoice', null, ['category' => $child->id]);
        $DB->set_field('question_versions', 'status', 'hidden', ['questionid' => $question->id]);
        $before = $DB->get_records('question_categories', ['contextid' => $context->id]);
        $this->assert_error('deletecategoryoccupied', fn() => $this->apply($preview));
        $this->assert_error('deletecategoryoccupied', fn() => $this->category_preview($context, $leaf->id, true));
        $this->assertEquals($before, $DB->get_records('question_categories', ['contextid' => $context->id]));
        $this->assertTrue($DB->record_exists('question', ['id' => $question->id]));
        $this->assertSame(0, $DB->count_records_select('local_bosonanceqbank_delete', 'timeused > 0'));
    }

    public function test_recursive_preview_is_invalidated_by_new_or_moved_descendants(): void {
        global $DB;
        [$context, $category, $leaf] = $this->fixture();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $preview = $this->category_preview($context, $leaf->id, true);
        $child = $generator->create_question_category(['contextid' => $context->id, 'parent' => $leaf->id]);
        $this->assert_error('deletestale', fn() => $this->apply($preview));
        $preview = $this->category_preview($context, $leaf->id, true);
        $DB->set_field('question_categories', 'parent', $category->id, ['id' => $child->id]);
        $this->assert_error('deletestale', fn() => $this->apply($preview));
        $this->assertTrue($DB->record_exists('question_categories', ['id' => $leaf->id]));
        $this->assertTrue($DB->record_exists('question_categories', ['id' => $child->id]));
    }

    public function test_unused_question_cascade_deletes_all_versions_and_categories_atomically(): void {
        global $CFG, $DB;
        [$context, $parent, $leaf, $outsidequestion] = $this->fixture();
        $this->assert_error('invalidparameter',
            fn() => $this->category_preview($context, $leaf->id, true, true));
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $child = $generator->create_question_category(['contextid' => $context->id, 'parent' => $leaf->id]);
        $question = $generator->create_question('multichoice', null, ['category' => $leaf->id]);
        $nestedquestion = $generator->create_question('shortanswer', null, ['category' => $child->id]);
        $nestedlatest = $generator->create_question('shortanswer', null, ['category' => $child->id]);
        $nestedlatestversion = $DB->get_record('question_versions', ['questionid' => $nestedlatest->id], '*', MUST_EXIST);
        $DB->set_field('question_versions', 'version', 2, ['questionid' => $nestedlatest->id]);
        $DB->set_field('question_versions', 'questionbankentryid', $DB->get_field('question_versions',
            'questionbankentryid', ['questionid' => $nestedquestion->id]), ['questionid' => $nestedlatest->id]);
        delete_question_bank_entry($nestedlatestversion->questionbankentryid);
        $DB->set_field('question_versions', 'status', 'hidden', ['questionid' => $question->id]);
        $DB->set_field('question_versions', 'status', 'draft', ['questionid' => $nestedquestion->id]);
        $CFG->usetags = true;
        \core_tag_tag::set_item_tags('core_question', 'question', $question->id, $context, ['Cascade QA']);
        $tag = $DB->get_record('tag', ['name' => 'Cascade QA'], '*', MUST_EXIST);
        $preview = $this->category_preview($context, $leaf->id, false, true);
        $competing = $this->category_preview($context, $leaf->id, false, true);
        $this->assertTrue($preview['recursive']);
        $this->assertTrue($preview['deleteunusedquestions']);
        $this->assertSame(2, $preview['categorycount']);
        $this->assertSame(2, $preview['entrycount']);
        $this->assertSame(3, $preview['questioncount']);
        $this->assertSame([$question->id, $nestedlatest->id],
            array_column($preview['questionentries'], 'questionid'));
        $this->assertSame(2, $preview['questionentries'][1]['questioncount']);
        $this->assertSame([$nestedquestion->id, $nestedlatest->id],
            array_column($preview['questionentries'][1]['versions'], 'questionid'));
        $sink = $this->redirectEvents();
        $result = $this->apply($preview);
        $this->assertTrue($result['deleted']);
        $this->assertSame(2, $result['entrycount']);
        $this->assertSame(3, $result['questioncount']);
        foreach ([$leaf, $child] as $deletedcategory) {
            $this->assertFalse($DB->record_exists('question_categories', ['id' => $deletedcategory->id]));
        }
        foreach ([$question, $nestedquestion, $nestedlatest] as $deletedquestion) {
            $this->assertFalse($DB->record_exists('question', ['id' => $deletedquestion->id]));
        }
        foreach ($preview['questionentries'] as $entry) {
            $this->assertFalse($DB->record_exists('question_bank_entries',
                ['id' => $entry['entryid']]));
        }
        $this->assertTrue($DB->record_exists('question', ['id' => $outsidequestion->id]));
        $this->assertTrue($DB->record_exists('question_categories', ['id' => $parent->id]));
        $this->assertTrue($DB->record_exists('tag', ['id' => $tag->id]));
        $this->assertFalse($DB->record_exists('tag_instance', [
            'component' => 'core_question', 'itemtype' => 'question', 'itemid' => $question->id,
        ]));
        $this->assertCount(3, array_filter($sink->get_events(),
            fn($event) => $event instanceof \core\event\question_deleted));
        $this->assertCount(2, array_filter($sink->get_events(),
            fn($event) => $event instanceof \core\event\question_category_deleted));
        $audit = $DB->get_record('local_bosonanceqbank_delete', ['id' => $result['deletionid']], '*', MUST_EXIST);
        $details = json_decode($audit->details, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(2, $details['entrycount']);
        foreach ($details['questionentries'] as $entry) {
            $this->assertArrayNotHasKey('name', $entry);
        }
        foreach ($details['categories'] as $category) {
            $this->assertArrayNotHasKey('name', $category);
        }
        $this->assert_error('deletepreviewused', fn() => $this->apply($preview));
        $this->assert_error('deletestale', fn() => $this->apply($competing));
    }

    public function test_unused_question_cascade_rejects_referenced_question_without_partial_deletion(): void {
        global $DB;
        [$context, , $leaf] = $this->fixture();
        $question = $this->getDataGenerator()->get_plugin_generator('core_question')
            ->create_question('multichoice', null, ['category' => $leaf->id]);
        $preview = $this->category_preview($context, $leaf->id, false, true);
        $DB->insert_record('question_references', (object)[
            'usingcontextid' => $context->id, 'component' => 'mod_quiz', 'questionarea' => 'slot',
            'itemid' => 999, 'questionbankentryid' => $preview['questionentries'][0]['entryid'], 'version' => null,
        ]);
        $before = $DB->get_records('question_categories', ['contextid' => $context->id]);
        $this->assert_error('deleteinuse', fn() => $this->apply($preview));
        $this->assertEquals($before, $DB->get_records('question_categories', ['contextid' => $context->id]));
        $this->assertTrue($DB->record_exists('question', ['id' => $question->id]));
        $this->assertTrue($DB->record_exists('question_categories', ['id' => $leaf->id]));
        $this->assertSame(0, $DB->count_records_select('local_bosonanceqbank_delete', 'timeused > 0'));
    }

    public function test_unused_question_cascade_invalidates_on_question_content_change(): void {
        global $DB;
        [$context, , $leaf] = $this->fixture();
        $question = $this->getDataGenerator()->get_plugin_generator('core_question')
            ->create_question('multichoice', null, ['category' => $leaf->id]);
        $preview = $this->category_preview($context, $leaf->id, false, true);
        $DB->set_field('question', 'questiontext', 'Changed after preview', ['id' => $question->id]);
        $this->assert_error('deletestale', fn() => $this->apply($preview));
        $this->assertTrue($DB->record_exists('question', ['id' => $question->id]));
        $this->assertTrue($DB->record_exists('question_categories', ['id' => $leaf->id]));
    }

    public function test_recursive_category_limit_includes_the_selected_root(): void {
        global $DB;
        [$context, , $leaf] = $this->fixture();
        $children = [];
        for ($index = 0; $index < 999; $index++) {
            $child = clone $leaf;
            unset($child->id);
            $child->parent = $leaf->id;
            $child->name = 'Bound ' . $index;
            $child->stamp = 'recursive-limit-' . $index;
            $children[] = $child;
        }
        $DB->insert_records('question_categories', $children);
        $preview = $this->category_preview($context, $leaf->id, true);
        $this->assertSame(1000, $preview['categorycount']);
        $extra = clone $children[0];
        $extra->stamp = 'recursive-limit-extra';
        $DB->insert_record('question_categories', $extra);
        $this->assert_error('deletelimit', fn() => $this->category_preview($context, $leaf->id, true));
        $this->assertSame(0, $DB->count_records_select('local_bosonanceqbank_delete', 'timeused > 0'));
    }

    public function test_content_answer_tag_and_file_edits_invalidate_question_preview(): void {
        global $CFG, $DB;
        [$context, , , $question] = $this->fixture();
        $CFG->usetags = true;
        $answers = $DB->get_records('question_answers', ['question' => $question->id]);
        $actions = [
            fn() => $DB->set_field('question', 'questiontext', 'Edited without changing timestamp', ['id' => $question->id]),
            fn() => $DB->set_field('question_answers', 'feedback', 'Changed answer feedback', ['id' => array_key_first($answers)]),
            fn() => \core_tag_tag::set_item_tags('core_question', 'question', $question->id, $context, ['New tag']),
            fn() => get_file_storage()->create_file_from_string([
                'contextid' => $context->id, 'component' => 'question', 'filearea' => 'questiontext',
                'itemid' => $question->id, 'filepath' => '/', 'filename' => 'new.txt',
            ], 'New file'),
        ];
        foreach ($actions as $action) {
            $preview = $this->question_preview($context, $question->id);
            $action();
            $this->assert_error('deletestale', fn() => $this->apply($preview));
            $this->assertTrue($DB->record_exists('question', ['id' => $question->id]));
        }
        $this->assertSame(0, $DB->count_records_select('local_bosonanceqbank_delete', 'timeused > 0'));
    }

    public function test_new_version_and_reference_reject_deletion_without_consuming_preview(): void {
        global $DB;
        [$context, $category, , $question, $version] = $this->fixture();
        $preview = $this->question_preview($context, $question->id);
        $other = $this->getDataGenerator()->get_plugin_generator('core_question')
            ->create_question('multichoice', null, ['category' => $category->id]);
        $DB->set_field('question_versions', 'version', 2, ['questionid' => $other->id]);
        $DB->set_field('question_versions', 'questionbankentryid', $version->questionbankentryid, ['questionid' => $other->id]);
        $this->assert_error('deletestale', fn() => $this->apply($preview));
        $preview = $this->question_preview($context, $other->id, 2);
        $DB->insert_record('question_references', (object)[
            'usingcontextid' => $context->id, 'component' => 'mod_quiz', 'questionarea' => 'slot',
            'itemid' => 999, 'questionbankentryid' => $version->questionbankentryid, 'version' => null,
        ]);
        $this->assert_error('deleteinuse', fn() => $this->apply($preview));
        $this->assertTrue($DB->record_exists('question', ['id' => $question->id]));
        $this->assertTrue($DB->record_exists('question', ['id' => $other->id]));
        $this->assertSame(0, $DB->count_records_select('local_bosonanceqbank_delete', 'timeused > 0'));
    }

    public function test_calculated_dataset_edit_invalidates_preview(): void {
        global $DB;
        [$context, $category] = $this->fixture();
        $question = $this->getDataGenerator()->get_plugin_generator('core_question')
            ->create_question('calculated', 'sum', ['category' => $category->id]);
        $items = [];
        foreach (['a', 'b'] as $name) {
            $definitionid = $DB->insert_record('question_dataset_definitions', (object)[
                'category' => 0, 'name' => $name, 'type' => 1, 'options' => 'uniform:1.0:10.0:1', 'itemcount' => 1,
            ]);
            $DB->insert_record('question_datasets', (object)[
                'question' => $question->id, 'datasetdefinition' => $definitionid,
            ]);
            $items[$name] = $DB->insert_record('question_dataset_items', (object)[
                'definition' => $definitionid, 'itemnumber' => 1, 'value' => '2.5',
            ]);
        }
        $itemid = $items['a'];
        $preview = $this->question_preview($context, $question->id);
        $DB->set_field('question_dataset_items', 'value', '3.5', ['id' => $itemid]);
        $this->assert_error('deletestale', fn() => $this->apply($preview));
        $this->assertTrue($DB->record_exists('question', ['id' => $question->id]));
        $this->assertSame('3.5', $DB->get_field('question_dataset_items', 'value', ['id' => $itemid]));
    }

    public function test_attempts_and_question_set_references_block_question_deletion(): void {
        global $DB;
        [$context, , , $question] = $this->fixture();
        $preview = $this->question_preview($context, $question->id);
        $usage = \question_engine::make_questions_usage_by_activity('mod_quiz', $context);
        $usage->set_preferred_behaviour('deferredfeedback');
        $usage->add_question(\question_bank::load_question($question->id));
        $usage->start_all_questions();
        \question_engine::save_questions_usage_by_activity($usage);
        $this->assert_error('deleteinuse', fn() => $this->apply($preview));
        \question_engine::delete_questions_usage_by_activity($usage->get_id());
        $preview = $this->question_preview($context, $question->id);
        $DB->insert_record('question_set_references', (object)[
            'usingcontextid' => $context->id, 'component' => 'mod_quiz', 'questionarea' => 'slot',
            'itemid' => 999, 'questionscontextid' => $context->id, 'filtercondition' => '{}',
        ]);
        $this->assert_error('deleteinuse', fn() => $this->apply($preview));
        $this->assertTrue($DB->record_exists('question', ['id' => $question->id]));
    }

    public function test_child_questions_and_occupied_categories_are_never_cascaded(): void {
        global $DB;
        [$context, $category, $leaf, $question] = $this->fixture();
        $this->assert_error('deletecategoryoccupied', fn() => $this->category_preview($context, $category->id));
        $preview = $this->category_preview($context, $leaf->id);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $generator->create_question('multichoice', null, ['category' => $leaf->id]);
        $this->assert_error('deletecategoryoccupied', fn() => $this->apply($preview));
        $preview = $this->question_preview($context, $question->id);
        $child = $generator->create_question('multichoice', null, ['category' => $category->id]);
        $DB->set_field('question', 'parent', $question->id, ['id' => $child->id]);
        $this->assert_error('deleteunsupported', fn() => $this->apply($preview));
        $this->assertTrue($DB->record_exists('question_categories', ['id' => $leaf->id]));
        $this->assertTrue($DB->record_exists('question', ['id' => $question->id]));
    }

    public function test_top_and_sole_top_child_are_protected(): void {
        global $DB;
        [$context, $category, $leaf, $question] = $this->fixture();
        $top = question_get_top_category($context->id);
        $this->assert_error('cannotdeletetopcat', fn() => $this->category_preview($context, $top->id));
        $this->assert_error('cannotdeletetopcat', fn() => $this->category_preview($context, $top->id, true));
        question_delete_question($question->id);
        $manager = new \core_question\category_manager();
        $manager->delete_category($leaf->id);
        $manager->delete_category($category->id);
        $remaining = $DB->get_record('question_categories', ['parent' => $top->id], '*', MUST_EXIST);
        $this->assert_error('cannotdeletecate', fn() => $this->category_preview($context, $remaining->id));
        $this->assert_error('cannotdeletecate', fn() => $this->category_preview($context, $remaining->id, true));
    }

    public function test_category_metadata_changes_reject_deletion(): void {
        global $DB;
        [$context, , $leaf] = $this->fixture();
        $preview = $this->category_preview($context, $leaf->id);
        $DB->set_field('question_categories', 'name', 'Renamed elsewhere', ['id' => $leaf->id]);
        $this->assert_error('deletestale', fn() => $this->apply($preview));
        $this->assertTrue($DB->record_exists('question_categories', ['id' => $leaf->id]));
    }

    public function test_preview_kind_caller_expiry_and_feature_gates(): void {
        global $CFG, $DB;
        [$context, , , $question] = $this->fixture();
        $preview = $this->question_preview($context, $question->id);
        $this->assert_error('deletepreviewinvalid',
            fn() => delete_category::execute($preview['previewtoken'], true));
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assert_error('deletepreviewinvalid', fn() => $this->apply($preview));
        $this->setAdminUser();
        $DB->set_field('local_bosonanceqbank_delete', 'timeexpires', time() - 1, []);
        $this->assert_error('deletepreviewexpired', fn() => $this->apply($preview));
        unset($CFG->local_bosonanceqbank_enabledeletions);
        $this->assert_error('deletedisabled', fn() => $this->question_preview($context, $question->id));
        $this->assert_error('deletedisabled', fn() => $this->apply($preview));
        $this->assertTrue($DB->record_exists('question', ['id' => $question->id]));
    }

    public function test_privacy_removes_unused_previews_and_anonymises_minimal_audit(): void {
        global $DB;
        [$context, , $leaf, $question] = $this->fixture();
        $this->category_preview($context, $leaf->id);
        $result = $this->apply($this->question_preview($context, $question->id));
        privacy\provider::delete_data_for_all_users_in_context($context);
        $this->assertSame(1, $DB->count_records('local_bosonanceqbank_delete', ['contextid' => $context->id]));
        $audit = $DB->get_record('local_bosonanceqbank_delete', ['id' => $result['deletionid']], '*', MUST_EXIST);
        $this->assertSame(0, (int)$audit->userid);
        $details = json_decode($audit->details, true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('name', $details);
        $this->assertArrayNotHasKey('questiontext', $details);
        $this->assertArrayNotHasKey('answers', $details);
        $this->assertSame((int)$question->id, $details['questionid']);
        $this->assertGreaterThan(0, $audit->timeused);
    }
}
