<?php
namespace local_bosonanceqbank;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use local_bosonanceqbank\external\move_question;
use local_bosonanceqbank\external\preview_move;

final class move_test extends \advanced_testcase {
    private function fixture(): array {
        global $CFG, $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $CFG->local_bosonanceqbank_enablemoves = true;
        require_once($CFG->libdir . '/questionlib.php');
        $course = $this->getDataGenerator()->create_course();
        $bank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $context = \context_module::instance($bank->cmid);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $top = question_get_top_category($context->id, true);
        $source = $generator->create_question_category(['contextid' => $context->id, 'parent' => $top->id, 'name' => 'source']);
        $target = $generator->create_question_category(['contextid' => $context->id, 'parent' => $top->id, 'name' => 'target']);
        $question = $generator->create_question('multichoice', null, ['category' => $source->id]);
        $version = $DB->get_record('question_versions', ['questionid' => $question->id], '*', MUST_EXIST);
        return [$context, $source, $target, $question, $version];
    }

    private function preview(\context $context, int $questionid, int $version, int $targetid): array {
        return external_api::clean_returnvalue(preview_move::execute_returns(),
            preview_move::execute($context->id, $questionid, $version, $targetid));
    }

    private function apply(array $preview, bool $exclusive = true): array {
        return external_api::clean_returnvalue(move_question::execute_returns(),
            move_question::execute($preview['previewtoken'], $exclusive));
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

    public function test_preview_move_requires_explicit_confirmation_and_is_single_use(): void {
        global $DB;

        [$context, $source, $target, $question, $version] = $this->fixture();
        $preview = $this->preview($context, $question->id, 1, $target->id);
        $this->assertSame((int)$source->id, $preview['sourceid']);
        $this->assertSame((int)$version->questionbankentryid, $preview['entryid']);
        $this->assertCount(1, $preview['versions']);
        $this->assertSame((int)$source->id, (int)$DB->get_field('question_bank_entries', 'questioncategoryid',
            ['id' => $version->questionbankentryid]));
        $this->assert_error('invalidparameter', fn() => $this->apply($preview, false));
        $sink = $this->redirectEvents();
        $result = $this->apply($preview);
        $this->assertSame((int)$target->id, $result['targetid']);
        $this->assertSame((int)$target->id, (int)$DB->get_field('question_bank_entries', 'questioncategoryid',
            ['id' => $version->questionbankentryid]));
        $this->assertContains(\core\event\question_moved::class, array_map('get_class', $sink->get_events()));
        $this->assertGreaterThan(0, (int)$DB->get_field('local_bosonanceqbank_move', 'timeused', ['id' => $result['moveid']]));
        $this->assert_error('previewused', fn() => $this->apply($preview));
    }

    public function test_move_includes_all_versions_and_rejects_stale_latest_version(): void {
        global $DB;

        [$context, $source, $target, $question, $version] = $this->fixture();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $newquestion = $generator->create_question('multichoice', null, ['category' => $source->id]);
        $DB->set_field('question_versions', 'version', 2, ['questionid' => $newquestion->id]);
        $DB->set_field('question_versions', 'questionbankentryid', $version->questionbankentryid,
            ['questionid' => $newquestion->id]);
        $this->assert_error('previewstale', fn() => $this->preview($context, $question->id, 1, $target->id));
        $preview = $this->preview($context, $newquestion->id, 2, $target->id);
        $this->assertSame([(int)$question->id, (int)$newquestion->id], array_column($preview['versions'], 'questionid'));
        foreach ([$question->id, $newquestion->id] as $id) {
            $this->assertEquals($source->id, \question_bank::load_question_data($id)->category);
        }
        $result = $this->apply($preview);
        $this->assertCount(2, $result['versions']);
        foreach ([$question->id, $newquestion->id] as $id) {
            $this->assertEquals($target->id, \question_bank::load_question_data($id)->category);
        }
        $this->assertSame((int)$target->id, (int)$DB->get_field('question_bank_entries', 'questioncategoryid',
            ['id' => $version->questionbankentryid]));
    }

    public function test_changed_question_after_preview_is_rejected(): void {
        global $DB;

        [$context, $source, $target, $question, $version] = $this->fixture();
        $preview = $this->preview($context, $question->id, 1, $target->id);
        $DB->set_field('question', 'timemodified', time() + 10, ['id' => $question->id]);
        $this->assert_error('previewstale', fn() => $this->apply($preview));
        $this->assertSame((int)$source->id, (int)$DB->get_field('question_bank_entries', 'questioncategoryid',
            ['id' => $version->questionbankentryid]));
        $this->assertSame(0, (int)$DB->get_field('local_bosonanceqbank_move', 'timeused', []));
    }

    public function test_destination_idnumber_collision_and_children_are_blocked(): void {
        global $DB;

        [$context, $source, $target, $question, $version] = $this->fixture();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $other = $generator->create_question('multichoice', null, ['category' => $target->id]);
        $otherentry = $DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $other->id]);
        $DB->set_field('question_bank_entries', 'idnumber', 'same', ['id' => $version->questionbankentryid]);
        $DB->set_field('question_bank_entries', 'idnumber', 'same', ['id' => $otherentry]);
        $this->assert_error('movecollision', fn() => $this->preview($context, $question->id, 1, $target->id));
        $DB->set_field('question_bank_entries', 'idnumber', null, ['id' => $otherentry]);
        $DB->set_field('question', 'parent', $question->id, ['id' => $other->id]);
        $this->assert_error('movechildren', fn() => $this->preview($context, $question->id, 1, $target->id));
        $this->assertSame((int)$source->id, (int)$DB->get_field('question_bank_entries', 'questioncategoryid',
            ['id' => $version->questionbankentryid]));
    }

    public function test_collision_created_after_preview_is_rechecked(): void {
        global $DB;

        [$context, $source, $target, $question, $version] = $this->fixture();
        $DB->set_field('question_bank_entries', 'idnumber', 'same', ['id' => $version->questionbankentryid]);
        $preview = $this->preview($context, $question->id, 1, $target->id);
        $other = $this->getDataGenerator()->get_plugin_generator('core_question')
            ->create_question('multichoice', null, ['category' => $target->id]);
        $otherentry = $DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $other->id]);
        $DB->set_field('question_bank_entries', 'idnumber', 'same', ['id' => $otherentry]);
        $this->assert_error('movecollision', fn() => $this->apply($preview));
        $entry = $DB->get_record('question_bank_entries', ['id' => $version->questionbankentryid], '*', MUST_EXIST);
        $this->assertSame((int)$source->id, (int)$entry->questioncategoryid);
        $this->assertSame('same', $entry->idnumber);
        $this->assertSame(0, (int)$DB->get_field('local_bosonanceqbank_move', 'timeused', []));
    }

    public function test_cross_bank_and_question_set_references_are_rejected(): void {
        global $DB;

        [$context, $source, $target, $question] = $this->fixture();
        $course = $this->getDataGenerator()->create_course();
        $bank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $othercontext = \context_module::instance($bank->cmid);
        $othertop = question_get_top_category($othercontext->id, true);
        $other = $this->getDataGenerator()->get_plugin_generator('core_question')->create_question_category([
            'contextid' => $othercontext->id, 'parent' => $othertop->id, 'name' => 'other',
        ]);
        $this->assert_error('wrongcontext', fn() => $this->preview($context, $question->id, 1, $other->id));
        $DB->insert_record('question_set_references', (object)[
            'usingcontextid' => $context->id, 'component' => 'mod_quiz', 'questionarea' => 'slot',
            'itemid' => 999, 'questionscontextid' => $context->id, 'filtercondition' => '{}',
        ]);
        $this->assert_error('moveinuse', fn() => $this->preview($context, $question->id, 1, $target->id));
    }

    public function test_foreign_and_expired_tokens_are_rejected(): void {
        global $DB;

        [$context, $source, $target, $question, $version] = $this->fixture();
        $preview = $this->preview($context, $question->id, 1, $target->id);
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assert_error('previewinvalid', fn() => $this->apply($preview));
        $this->setAdminUser();
        $DB->set_field('local_bosonanceqbank_move', 'timeexpires', time() - 1, []);
        $this->assert_error('previewexpired', fn() => $this->apply($preview));
        $this->assertSame((int)$source->id, (int)$DB->get_field('question_bank_entries', 'questioncategoryid',
            ['id' => $version->questionbankentryid]));
    }

    public function test_out_of_context_tags_are_rejected(): void {
        global $DB;

        [$context, $source, $target, $question, $version] = $this->fixture();
        \core_tag_tag::set_item_tags('core_question', 'question', $question->id, $context, ['move-test']);
        $preview = $this->preview($context, $question->id, 1, $target->id);
        $DB->set_field('tag_instance', 'contextid', \context_system::instance()->id, [
            'component' => 'core_question', 'itemtype' => 'question', 'itemid' => $question->id,
        ]);
        $this->assert_error('movetags', fn() => $this->apply($preview));
        $this->assertSame((int)$source->id, (int)$DB->get_field('question_bank_entries', 'questioncategoryid',
            ['id' => $version->questionbankentryid]));
    }

    public function test_moves_are_disabled_by_default(): void {
        global $CFG;

        [$context, , $target, $question] = $this->fixture();
        unset($CFG->local_bosonanceqbank_enablemoves);
        $this->assert_error('movedisabled', fn() => $this->preview($context, $question->id, 1, $target->id));
    }
}
