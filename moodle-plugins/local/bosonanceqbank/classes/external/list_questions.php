<?php
namespace local_bosonanceqbank\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

class list_questions extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Bank module context ID'),
            'categoryid' => new external_value(PARAM_INT, 'Exact category, excluding descendants'),
            'offset' => new external_value(PARAM_INT, 'Offset', VALUE_DEFAULT, 0),
            'limit' => new external_value(PARAM_INT, 'Page size, at most 100', VALUE_DEFAULT, 50),
            'status' => new external_value(PARAM_ALPHA, 'ready, draft, hidden or all', VALUE_DEFAULT, 'ready'),
            'qtype' => new external_value(PARAM_PLUGIN, 'Question type filter', VALUE_DEFAULT, ''),
            'search' => new external_value(PARAM_RAW, 'Substring of name or text', VALUE_DEFAULT, ''),
            'tag' => new external_value(PARAM_RAW, 'Exact stored tag name', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(
        int $contextid, int $categoryid, int $offset = 0, int $limit = 50,
        string $status = 'ready', string $qtype = '', string $search = '', string $tag = ''
    ): array {
        global $DB;

        self::validate_parameters(self::execute_parameters(), compact(
            'contextid', 'categoryid', 'offset', 'limit', 'status', 'qtype', 'search', 'tag'
        ));
        access::bank($contextid);
        access::category($contextid, $categoryid);
        access::page($offset, $limit);
        if (!in_array($status, ['ready', 'draft', 'hidden', 'all'], true)) {
            throw new \invalid_parameter_exception(get_string('invalidstatus', 'local_bosonanceqbank'));
        }
        $from = ' FROM {question} q
                  JOIN {question_versions} qv ON qv.questionid = q.id
                  JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid';
        $where = ' WHERE qbe.questioncategoryid = :categoryid AND q.parent = 0
                     AND qv.version = (SELECT MAX(v.version) FROM {question_versions} v
                                        WHERE v.questionbankentryid = qbe.id)';
        $params = ['categoryid' => $categoryid];
        if ($status !== 'all') {
            $where .= ' AND qv.status = :status';
            $params['status'] = $status;
        }
        if ($qtype !== '') {
            $where .= ' AND q.qtype = :qtype';
            $params['qtype'] = $qtype;
        }
        if ($search !== '') {
            $where .= ' AND (' . $DB->sql_like('q.name', ':namesearch') . ' OR ' .
                $DB->sql_like('q.questiontext', ':textsearch') . ')';
            $params['namesearch'] = '%' . $DB->sql_like_escape($search) . '%';
            $params['textsearch'] = $params['namesearch'];
        }
        if ($tag !== '') {
            $where .= " AND EXISTS (SELECT 1 FROM {tag_instance} ti JOIN {tag} t ON t.id = ti.tagid
                                     WHERE ti.itemid = q.id AND ti.component = :tagcomponent
                                       AND ti.itemtype = :tagtype AND t.name = :tagname)";
            $params += ['tagcomponent' => 'core_question', 'tagtype' => 'question', 'tagname' => $tag];
        }
        $records = $DB->get_records_sql(
            'SELECT q.id, q.name, q.qtype, q.timemodified, qv.version, qv.status, qv.questionbankentryid,
                    qbe.questioncategoryid AS categoryid' . $from . $where . ' ORDER BY q.id ASC',
            $params, $offset, $limit
        );
        return [
            'total' => $DB->count_records_sql('SELECT COUNT(*)' . $from . $where, $params),
            'items' => array_values(array_map([access::class, 'summary'], $records)),
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'total' => new external_value(PARAM_INT, 'Total matching latest versions'),
            'items' => new external_multiple_structure(access::summary_schema()),
        ]);
    }
}
