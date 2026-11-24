<?php
namespace local_bosonanceqbank;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use local_bosonanceqbank\external\change_status;
use local_bosonanceqbank\external\preview_status;

final class status_test extends \advanced_testcase {
    private function fixture(): array {
        global $CFG, $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $CFG->local_bosonanceqbank_enablestatus = true;
        require_once($CFG->libdir . '/questionlib.php');
        $course = $this->getDataGenerator()->create_course();
        $bank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $context = \context_module::instance($bank->cmid);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $top = question_get_top_category($context->id, true);
        $category = $generator->create_question_category(['contextid' => $context->id, 'parent' => $top->id]);
        $question = $generator->create_question('multichoice', null, ['category' => $category->id]);
        $version = $DB->get_record('question_versions', ['questionid' => $question->id], '*', MUST_EXIST);
        return [$context, $question, $version];
    }

    private function preview(\context $context, int $questionid, string $old, string $new, int $version = 1): array {
        return external_api::clean_returnvalue(preview_status::execute_returns(),
            preview_status::execute($context->id, $questionid, $version, $old, $new));
    }

    private function apply(array $preview, bool $exclusive = true): array {
        return external_api::clean_returnvalue(change_status::execute_returns(),
            change_status::execute($preview['previewtoken'], $exclusive));
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

    public function test_transitions_are_previewed_confirmed_and_single_use(): void {
        global $DB;
        [$context, $question, $version] = $this->fixture();
        $sink = $this->redirectEvents();
        foreach ([['ready', 'draft'], ['draft', 'hidden'], ['hidden', 'ready']] as [$old, $new]) {
            $preview = $this->preview($context, $question->id, $old, $new);
            $this->assertSame($old, $DB->get_field('question_versions', 'status', ['id' => $version->id]));
            $this->assert_error('invalidparameter', fn() => $this->apply($preview, false));
            $result = $this->apply($preview);
            $this->assertSame($new, $result['newstatus']);
            $this->assertSame($new, $DB->get_field('question_versions', 'status', ['id' => $version->id]));
            $this->assertSame(1, $DB->count_records('question_versions',
                ['questionbankentryid' => $version->questionbankentryid]));
            $this->assertGreaterThan(0, $DB->get_field('local_bosonanceqbank_status', 'timeused', ['id' => $result['statusid']]));
            $this->assert_error('statuspreviewused', fn() => $this->apply($preview));
        }
        $events = array_filter($sink->get_events(), fn($event) => $event instanceof \core\event\question_updated);
        $this->assertCount(3, $events);
    }

    public function test_stale_status_and_version_do_not_change_question(): void {
        global $DB;
        [$context, $question, $version] = $this->fixture();
        $preview = $this->preview($context, $question->id, 'ready', 'draft');
        $DB->set_field('question_versions', 'status', 'hidden', ['id' => $version->id]);
        $this->assert_error('statusstale', fn() => $this->apply($preview));
        $this->assertSame('hidden', $DB->get_field('question_versions', 'status', ['id' => $version->id]));
        $this->assertSame(0, (int)$DB->get_field('local_bosonanceqbank_status', 'timeused', []));
        $this->assert_error('statusstale', fn() => $this->preview($context, $question->id, 'ready', 'draft'));
        $this->assert_error('statusstale', fn() => $this->preview($context, $question->id, 'hidden', 'ready', 2));
    }

    public function test_new_version_after_preview_is_rejected(): void {
        global $DB;
        [$context, $question, $version] = $this->fixture();
        $preview = $this->preview($context, $question->id, 'ready', 'draft');
        $categoryid = $DB->get_field('question_bank_entries', 'questioncategoryid', ['id' => $version->questionbankentryid]);
        $other = $this->getDataGenerator()->get_plugin_generator('core_question')
            ->create_question('multichoice', null, ['category' => $categoryid]);
        $DB->set_field('question_versions', 'version', 2, ['questionid' => $other->id]);
        $DB->set_field('question_versions', 'questionbankentryid', $version->questionbankentryid,
            ['questionid' => $other->id]);
        $this->assert_error('statusstale', fn() => $this->apply($preview));
        $this->assertSame('ready', $DB->get_field('question_versions', 'status', ['id' => $version->id]));
    }

    public function test_bad_tokens_and_targets_are_rejected(): void {
        global $CFG, $DB;
        [$context, $question] = $this->fixture();
        $this->assert_error('invalidparameter', fn() => $this->preview($context, $question->id, 'ready', 'ready'));
        $this->assert_error('invalidparameter', fn() => $this->preview($context, $question->id, 'ready', 'other'));
        $preview = $this->preview($context, $question->id, 'ready', 'draft');
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assert_error('statuspreviewinvalid', fn() => $this->apply($preview));
        $this->setAdminUser();
        $DB->set_field('local_bosonanceqbank_status', 'timeexpires', time() - 1, []);
        $this->assert_error('statuspreviewexpired', fn() => $this->apply($preview));
        unset($CFG->local_bosonanceqbank_enablestatus);
        $this->assert_error('statusdisabled', fn() => $this->preview($context, $question->id, 'ready', 'draft'));
    }

    public function test_question_set_references_are_rechecked_before_apply(): void {
        global $DB;
        [$context, $question, $version] = $this->fixture();
        $preview = $this->preview($context, $question->id, 'ready', 'hidden');
        $DB->insert_record('question_set_references', (object)[
            'usingcontextid' => $context->id, 'component' => 'mod_quiz', 'questionarea' => 'slot',
            'itemid' => 999, 'questionscontextid' => $context->id, 'filtercondition' => '{}',
        ]);
        $this->assert_error('statusinuse', fn() => $this->apply($preview));
        $this->assertSame('ready', $DB->get_field('question_versions', 'status', ['id' => $version->id]));
    }

    public function test_always_latest_reference_blocks_activating_a_draft(): void {
        global $DB;
        [$context, $question, $version] = $this->fixture();
        $DB->set_field('question_versions', 'status', 'draft', ['id' => $version->id]);
        $DB->insert_record('question_references', (object)[
            'usingcontextid' => $context->id, 'component' => 'mod_quiz', 'questionarea' => 'slot',
            'itemid' => 999, 'questionbankentryid' => $version->questionbankentryid, 'version' => null,
        ]);
        $this->assert_error('statusinuse', fn() => $this->preview($context, $question->id, 'draft', 'ready'));
        $this->assertSame('draft', $DB->get_field('question_versions', 'status', ['id' => $version->id]));
    }

    public function test_ambiguous_question_id_is_rejected(): void {
        global $DB;
        [$context, $question, $version] = $this->fixture();
        $categoryid = $DB->get_field('question_bank_entries', 'questioncategoryid', ['id' => $version->questionbankentryid]);
        $other = $this->getDataGenerator()->get_plugin_generator('core_question')
            ->create_question('multichoice', null, ['category' => $categoryid]);
        $DB->set_field('question_versions', 'questionid', $question->id, ['questionid' => $other->id]);
        $this->assert_error('statusambiguous', fn() => $this->preview($context, $question->id, 'ready', 'hidden'));
        $this->assertSame('ready', $DB->get_field('question_versions', 'status', ['id' => $version->id]));
    }
}
