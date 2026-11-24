<?php
namespace local_bosonanceblocks;

use core_external\external_api;
use local_bosonanceblocks\external\list_blocks;
use local_bosonanceblocks\external\update_html;

defined('MOODLE_INTERNAL') || die();

class external_test extends \advanced_testcase {
    private function create_html_block(string $text): \stdClass {
        $config = (object)[
            'title' => 'Original title',
            'text' => $text,
            'format' => FORMAT_HTML,
            'classes' => 'original-class',
        ];
        return $this->getDataGenerator()->create_block('html', [
            'configdata' => base64_encode(serialize($config)),
        ]);
    }

    public function test_list_all_block_types_and_html_content(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $html = $this->create_html_block('<p>Original</p>');
        $other = $this->getDataGenerator()->create_block('calendar_month');

        $result = external_api::clean_returnvalue(list_blocks::execute_returns(), list_blocks::execute());
        $byid = array_column($result, null, 'instanceid');
        $this->assertArrayHasKey($html->id, $byid);
        $this->assertArrayHasKey($other->id, $byid);
        $this->assertSame('<p>Original</p>', $byid[$html->id]['html']['content']);
        $this->assertSame(hash('sha256', '<p>Original</p>'), $byid[$html->id]['html']['contenthash']);
        $this->assertArrayNotHasKey('html', $byid[$other->id]);
    }

    public function test_update_preserves_files_and_other_configuration(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $record = $this->create_html_block('<p>Original</p>');
        $context = \context_block::instance($record->id);
        $fs = get_file_storage();
        $file = $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'block_html',
            'filearea' => 'content',
            'itemid' => 0,
            'filepath' => '/nested/',
            'filename' => 'example.txt',
        ], 'File content');
        $sink = $this->redirectEvents();

        $newcontent = '<p>Updated</p><a href="@@PLUGINFILE@@/nested/example.txt">File</a>';
        $result = update_html::execute($record->id, $newcontent, hash('sha256', '<p>Original</p>'));
        $result = external_api::clean_returnvalue(update_html::execute_returns(), $result);
        $saved = $DB->get_record('block_instances', ['id' => $record->id], '*', MUST_EXIST);
        $config = unserialize_object(base64_decode($saved->configdata));

        $this->assertSame($newcontent, $config->text);
        $this->assertSame(FORMAT_HTML, $config->format);
        $this->assertSame('Original title', $config->title);
        $this->assertSame('original-class', $config->classes);
        $this->assertSame($file->get_id(), $fs->get_file(
            $context->id, 'block_html', 'content', 0, '/nested/', 'example.txt'
        )->get_id());
        $this->assertSame(hash('sha256', $newcontent), $result['contenthash']);
        $this->assertCount(1, $sink->get_events());
        $this->assertInstanceOf(\local_bosonanceblocks\event\html_updated::class, $sink->get_events()[0]);
    }

    public function test_update_rejects_stale_content(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $record = $this->create_html_block('<p>Original</p>');
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('stalecontent', 'local_bosonanceblocks'));
        update_html::execute($record->id, '<p>Replacement</p>', hash('sha256', 'outdated'));
    }

    public function test_update_rejects_non_html_block(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $record = $this->getDataGenerator()->create_block('calendar_month');
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('nothtmlblock', 'local_bosonanceblocks'));
        update_html::execute($record->id, '<p>Replacement</p>', hash('sha256', ''));
    }

    public function test_non_admin_cannot_list_or_update(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $record = $this->create_html_block('<p>Original</p>');
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        try {
            list_blocks::execute();
            $this->fail('A non-admin must not be able to list blocks');
        } catch (\moodle_exception $exception) {
            $this->assertSame('adminrequired', $exception->errorcode);
        }
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('adminrequired', 'local_bosonanceblocks'));
        update_html::execute($record->id, '<p>Replacement</p>', hash('sha256', '<p>Original</p>'));
    }
}
