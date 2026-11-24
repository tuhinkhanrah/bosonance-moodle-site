<?php
namespace local_bosonanceqbank;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use local_bosonanceqbank\external\change_tags;
use local_bosonanceqbank\external\preview_tags;

final class tags_test extends \advanced_testcase {
    private function fixture(): array {
        global $CFG;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $CFG->local_bosonanceqbank_enabletags = true;
        require_once($CFG->libdir . '/questionlib.php');
        $course = $this->getDataGenerator()->create_course();
        $bank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $context = \context_module::instance($bank->cmid);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $top = question_get_top_category($context->id, true);
        $category = $generator->create_question_category(['contextid' => $context->id, 'parent' => $top->id]);
        $question = $generator->create_question('multichoice', null, ['category' => $category->id]);
        $collid = \core_tag_area::get_collection('core_question', 'question');
        $tag1 = $this->getDataGenerator()->create_tag(['rawname' => 'Tag alpha', 'tagcollid' => $collid, 'isstandard' => 1]);
        $tag2 = $this->getDataGenerator()->create_tag(['rawname' => 'Tag beta', 'tagcollid' => $collid, 'isstandard' => 1]);
        return [$context, $question, $tag1, $tag2];
    }

    private function preview(\context $context, int $questionid, array $tagids): array {
        return external_api::clean_returnvalue(preview_tags::execute_returns(),
            preview_tags::execute($context->id, $questionid, 1, $tagids));
    }

    private function apply(array $preview, bool $exclusive = true): array {
        return external_api::clean_returnvalue(change_tags::execute_returns(),
            change_tags::execute($preview['previewtoken'], $exclusive));
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

    public function test_preview_confirm_replay_and_clear_standard_tags(): void {
        global $DB;
        [$context, $question, $tag1, $tag2] = $this->fixture();
        $preview = $this->preview($context, $question->id, [(int)$tag1->id, (int)$tag2->id]);
        $this->assertSame([], $preview['oldtags']);
        $this->assertSame([(int)$tag1->id, (int)$tag2->id], array_column($preview['newtags'], 'id'));
        $this->assertCount(0, \core_tag_tag::get_item_tags('core_question', 'question', $question->id));
        $this->assert_error('invalidparameter', fn() => $this->apply($preview, false));
        $result = $this->apply($preview);
        $this->assertSame($preview['newtags'], $result['newtags']);
        $this->assertGreaterThan(0, $DB->get_field('local_bosonanceqbank_tags', 'timeused', ['id' => $result['tagsid']]));
        $this->assert_error('tagspreviewused', fn() => $this->apply($preview));
        $reorder = $this->preview($context, $question->id, [(int)$tag2->id, (int)$tag1->id]);
        $this->apply($reorder);
        $this->assertSame([(int)$tag2->id, (int)$tag1->id], array_map('intval', array_keys(
            \core_tag_tag::get_item_tags_array('core_question', 'question', $question->id))));
        $clear = $this->preview($context, $question->id, []);
        $this->apply($clear);
        $this->assertCount(0, \core_tag_tag::get_item_tags('core_question', 'question', $question->id));
        $this->assertTrue($DB->record_exists('tag', ['id' => $tag1->id]));
        $this->assertTrue($DB->record_exists('tag', ['id' => $tag2->id]));
    }

    public function test_changed_assignments_after_preview_are_rejected(): void {
        global $DB;
        [$context, $question, $tag1, $tag2] = $this->fixture();
        $preview = $this->preview($context, $question->id, [(int)$tag1->id]);
        \core_tag_tag::set_item_tags('core_question', 'question', $question->id, $context, [$tag2->rawname]);
        $this->assert_error('tagsstale', fn() => $this->apply($preview));
        $this->assertSame(0, (int)$DB->get_field('local_bosonanceqbank_tags', 'timeused', []));
        $this->assertSame([(int)$tag2->id], array_map('intval', array_keys(
            \core_tag_tag::get_item_tags_array('core_question', 'question', $question->id))));
    }

    public function test_nonstandard_and_invalid_tags_are_rejected(): void {
        global $CFG;
        [$context, $question, $tag1] = $this->fixture();
        $collid = \core_tag_area::get_collection('core_question', 'question');
        $other = $this->getDataGenerator()->create_tag(['rawname' => 'Nonstandard', 'tagcollid' => $collid]);
        $this->assert_error('tagsunsupported', fn() => $this->preview($context, $question->id, [(int)$other->id]));
        $this->assert_error('invalidparameter', fn() => $this->preview($context, $question->id, [(int)$tag1->id, (int)$tag1->id]));
        \core_tag_tag::set_item_tags('core_question', 'question', $question->id, $context, [$other->rawname]);
        $this->assert_error('tagsunsupported', fn() => $this->preview($context, $question->id, [(int)$tag1->id]));
        unset($CFG->local_bosonanceqbank_enabletags);
        $this->assert_error('tagsdisabled', fn() => $this->preview($context, $question->id, [(int)$tag1->id]));
    }

    public function test_foreign_and_expired_tokens_are_rejected(): void {
        global $DB;
        [$context, $question, $tag1] = $this->fixture();
        $preview = $this->preview($context, $question->id, [(int)$tag1->id]);
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assert_error('tagspreviewinvalid', fn() => $this->apply($preview));
        $this->setAdminUser();
        $DB->set_field('local_bosonanceqbank_tags', 'timeexpires', time() - 1, []);
        $this->assert_error('tagspreviewexpired', fn() => $this->apply($preview));
        $this->assertCount(0, \core_tag_tag::get_item_tags('core_question', 'question', $question->id));
    }

    public function test_reference_added_after_preview_blocks_apply(): void {
        global $DB;
        [$context, $question, $tag1] = $this->fixture();
        $preview = $this->preview($context, $question->id, [(int)$tag1->id]);
        $DB->insert_record('question_set_references', (object)[
            'usingcontextid' => $context->id, 'component' => 'mod_quiz', 'questionarea' => 'slot',
            'itemid' => 999, 'questionscontextid' => $context->id, 'filtercondition' => '{}',
        ]);
        $this->assert_error('tagsinuse', fn() => $this->apply($preview));
        $this->assertCount(0, \core_tag_tag::get_item_tags('core_question', 'question', $question->id));
        $this->assertSame(0, (int)$DB->get_field('local_bosonanceqbank_tags', 'timeused', []));
    }

    public function test_out_of_context_instances_are_rejected(): void {
        [$context, $question, $tag1, $tag2] = $this->fixture();
        \core_tag_tag::set_item_tags('core_question', 'question', $question->id, \context_system::instance(),
            [$tag1->rawname]);
        $this->assert_error('tagsunsupported', fn() => $this->preview($context, $question->id, [(int)$tag2->id]));
    }
}
