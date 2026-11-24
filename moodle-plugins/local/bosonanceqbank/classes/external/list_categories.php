<?php
namespace local_bosonanceqbank\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

class list_categories extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Bank module context ID'),
            'offset' => new external_value(PARAM_INT, 'Offset', VALUE_DEFAULT, 0),
            'limit' => new external_value(PARAM_INT, 'Page size, at most 100', VALUE_DEFAULT, 50),
        ]);
    }

    public static function execute(int $contextid, int $offset = 0, int $limit = 50): array {
        global $DB;

        self::validate_parameters(self::execute_parameters(), compact('contextid', 'offset', 'limit'));
        access::bank($contextid);
        access::page($offset, $limit);
        $records = $DB->get_records('question_categories', ['contextid' => $contextid], 'id ASC', '*', $offset, $limit);
        $items = [];
        foreach ($records as $category) {
            $items[] = [
                'categoryid' => (int)$category->id,
                'parentid' => (int)$category->parent,
                'name' => $category->name,
                'entrycount' => $DB->count_records('question_bank_entries', ['questioncategoryid' => $category->id]),
            ];
        }
        return ['total' => $DB->count_records('question_categories', ['contextid' => $contextid]), 'items' => $items];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'total' => new external_value(PARAM_INT, 'Total categories in this context'),
            'items' => new external_multiple_structure(new external_single_structure([
                'categoryid' => new external_value(PARAM_INT, 'Category ID'),
                'parentid' => new external_value(PARAM_INT, 'Parent category ID'),
                'name' => new external_value(PARAM_RAW, 'Stored category name'),
                'entrycount' => new external_value(PARAM_INT, 'Direct bank entries, including hidden/draft'),
            ])),
        ]);
    }
}
