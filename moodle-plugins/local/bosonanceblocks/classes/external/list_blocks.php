<?php
namespace local_bosonanceblocks\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

class list_blocks extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        global $CFG, $DB;

        self::validate_parameters(self::execute_parameters(), []);
        access::require_admin();
        require_once($CFG->libdir . '/blocklib.php');

        $blocks = [];
        $records = $DB->get_recordset('block_instances', null, 'id ASC');
        try {
            foreach ($records as $record) {
                $blockcontext = \context_block::instance($record->id);
                $item = [
                    'instanceid' => $record->id,
                    'blockname' => $record->blockname,
                    'contextid' => $blockcontext->id,
                    'parentcontextid' => $record->parentcontextid,
                    'pagetypepattern' => $record->pagetypepattern,
                    'subpagepattern' => $record->subpagepattern,
                    'showinsubcontexts' => $record->showinsubcontexts,
                    'defaultregion' => $record->defaultregion,
                    'defaultweight' => $record->defaultweight,
                ];
                if ($record->blockname === 'html') {
                    $block = block_instance('html', $record);
                    if (!$block) {
                        throw new \moodle_exception('cannotloadhtml', 'local_bosonanceblocks');
                    }
                    $content = $block->config->text ?? '';
                    $item['html'] = [
                        'title' => $block->config->title ?? '',
                        'content' => $content,
                        'format' => $block->config->format ?? FORMAT_HTML,
                        'contenthash' => hash('sha256', $content),
                    ];
                }
                $blocks[] = $item;
            }
        } finally {
            $records->close();
        }
        return $blocks;
    }

    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure([
            'instanceid' => new external_value(PARAM_INT, 'Block instance ID'),
            'blockname' => new external_value(PARAM_PLUGIN, 'Block type'),
            'contextid' => new external_value(PARAM_INT, 'Block context ID'),
            'parentcontextid' => new external_value(PARAM_INT, 'Parent context ID'),
            'pagetypepattern' => new external_value(PARAM_RAW, 'Page type pattern'),
            'subpagepattern' => new external_value(PARAM_RAW, 'Subpage pattern', VALUE_REQUIRED, null, NULL_ALLOWED),
            'showinsubcontexts' => new external_value(PARAM_BOOL, 'Whether the block appears in subcontexts'),
            'defaultregion' => new external_value(PARAM_RAW, 'Default region'),
            'defaultweight' => new external_value(PARAM_INT, 'Default weight'),
            'html' => new external_single_structure([
                'title' => new external_value(PARAM_RAW, 'Stored block title'),
                'content' => new external_value(PARAM_RAW, 'Stored, unformatted HTML block content'),
                'format' => new external_value(PARAM_INT, 'Stored text format'),
                'contenthash' => new external_value(PARAM_ALPHANUM, 'SHA-256 of stored content'),
            ], 'HTML block configuration', VALUE_OPTIONAL),
        ]));
    }
}
