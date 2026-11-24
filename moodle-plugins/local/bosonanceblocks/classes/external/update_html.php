<?php
namespace local_bosonanceblocks\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

class update_html extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'instanceid' => new external_value(PARAM_INT, 'HTML block instance ID'),
            'content' => new external_value(PARAM_RAW, 'New HTML content'),
            'expectedhash' => new external_value(PARAM_RAW, 'SHA-256 of the current stored content'),
        ]);
    }

    public static function execute(int $instanceid, string $content, string $expectedhash): array {
        global $CFG, $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'instanceid' => $instanceid,
            'content' => $content,
            'expectedhash' => $expectedhash,
        ]);
        access::require_admin();
        if (!preg_match('/\A[0-9a-f]{64}\z/', $params['expectedhash'])) {
            throw new \moodle_exception('invalidhash', 'local_bosonanceblocks');
        }
        require_once($CFG->libdir . '/blocklib.php');
        require_once($CFG->libdir . '/filelib.php');

        $record = $DB->get_record('block_instances', ['id' => $params['instanceid']], '*', MUST_EXIST);
        if ($record->blockname !== 'html') {
            throw new \moodle_exception('nothtmlblock', 'local_bosonanceblocks');
        }
        $context = \context_block::instance($record->id);
        self::validate_context($context);
        $block = block_instance('html', $record);
        if (!$block) {
            throw new \moodle_exception('cannotloadhtml', 'local_bosonanceblocks');
        }
        $current = $block->config->text ?? '';
        if (!hash_equals(hash('sha256', $current), $params['expectedhash'])) {
            throw new \moodle_exception('stalecontent', 'local_bosonanceblocks');
        }

        $config = isset($block->config) ? clone($block->config) : new \stdClass();
        $config->text = [
            'text' => $params['content'],
            'format' => FORMAT_HTML,
            'itemid' => IGNORE_FILE_MERGE,
        ];
        $transaction = $DB->start_delegated_transaction();
        $block->instance_config_save($config);
        \local_bosonanceblocks\event\html_updated::create([
            'context' => $context,
            'objectid' => $record->id,
        ])->trigger();
        $transaction->allow_commit();

        return [
            'instanceid' => $record->id,
            'contenthash' => hash('sha256', $params['content']),
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'instanceid' => new external_value(PARAM_INT, 'Updated block instance ID'),
            'contenthash' => new external_value(PARAM_ALPHANUM, 'SHA-256 of saved content'),
        ]);
    }
}
