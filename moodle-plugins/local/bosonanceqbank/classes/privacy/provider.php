<?php
namespace local_bosonanceqbank\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Preview rows are deleted; import audit rows are kept but anonymised so imported questions stay traceable.
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider {

    private const TABLES = ['preview' => 'local_bosonanceqbank_preview', 'import' => 'local_bosonanceqbank_import'];
    private const MOVES = 'local_bosonanceqbank_move';
    private const STATUSES = 'local_bosonanceqbank_status';
    private const TAGS = 'local_bosonanceqbank_tags';

    public static function get_metadata(collection $collection): collection {
        $common = [
            'userid' => 'privacy:metadata:userid',
            'contextid' => 'privacy:metadata:contextid',
            'categoryid' => 'privacy:metadata:categoryid',
            'filename' => 'privacy:metadata:filename',
            'sha256' => 'privacy:metadata:sha256',
            'timecreated' => 'privacy:metadata:timecreated',
        ];
        $collection->add_database_table(self::TABLES['preview'], $common, 'privacy:metadata:preview');
        $collection->add_database_table(self::TABLES['import'],
            $common + ['questionids' => 'privacy:metadata:questionids'], 'privacy:metadata:import');
        $collection->add_database_table(self::MOVES, [
            'userid' => 'privacy:metadata:userid', 'contextid' => 'privacy:metadata:contextid',
            'sourceid' => 'privacy:metadata:sourceid', 'targetid' => 'privacy:metadata:targetid',
            'entryid' => 'privacy:metadata:entryid', 'questionid' => 'privacy:metadata:questionid',
            'versions' => 'privacy:metadata:versions', 'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:move');
        $collection->add_database_table(self::STATUSES, [
            'userid' => 'privacy:metadata:userid', 'contextid' => 'privacy:metadata:contextid',
            'categoryid' => 'privacy:metadata:categoryid', 'entryid' => 'privacy:metadata:entryid',
            'questionid' => 'privacy:metadata:questionid', 'oldstatus' => 'privacy:metadata:oldstatus',
            'newstatus' => 'privacy:metadata:newstatus', 'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:status');
        $collection->add_database_table(self::TAGS, [
            'userid' => 'privacy:metadata:userid', 'contextid' => 'privacy:metadata:contextid',
            'categoryid' => 'privacy:metadata:categoryid', 'entryid' => 'privacy:metadata:entryid',
            'questionid' => 'privacy:metadata:questionid', 'oldtags' => 'privacy:metadata:oldtags',
            'newtags' => 'privacy:metadata:newtags', 'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:tags');
        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                 WHERE ctx.id IN (SELECT contextid FROM {" . self::TABLES['preview'] . "} WHERE userid = :u1)
                    OR ctx.id IN (SELECT contextid FROM {" . self::TABLES['import'] . "} WHERE userid = :u2)
                    OR ctx.id IN (SELECT contextid FROM {" . self::MOVES . "} WHERE userid = :u3)
                    OR ctx.id IN (SELECT contextid FROM {" . self::STATUSES . "} WHERE userid = :u4)
                    OR ctx.id IN (SELECT contextid FROM {" . self::TAGS . "} WHERE userid = :u5)";
        $contextlist->add_from_sql($sql, ['u1' => $userid, 'u2' => $userid, 'u3' => $userid, 'u4' => $userid, 'u5' => $userid]);
        return $contextlist;
    }

    public static function get_users_in_context(userlist $userlist): void {
        $params = ['contextid' => $userlist->get_context()->id];
        foreach (array_merge(array_values(self::TABLES), [self::MOVES, self::STATUSES, self::TAGS]) as $table) {
            $userlist->add_from_sql('userid', "SELECT userid FROM {{$table}} WHERE contextid = :contextid AND userid > 0",
                $params);
        }
    }

    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $data = [];
            foreach (self::TABLES as $key => $table) {
                $records = $DB->get_records($table, ['userid' => $userid, 'contextid' => $context->id], 'id ASC',
                    'id, categoryid, filename, sha256, questioncount, timecreated' . ($key === 'import' ? ', questionids' : ''));
                $data[$key] = array_values(array_map(static function($r) {
                    $r = (array)$r;
                    unset($r['id']);
                    $r['timecreated'] = transform::datetime($r['timecreated']);
                    return (object)$r;
                }, $records));
            }
            $moves = $DB->get_records(self::MOVES, ['userid' => $userid, 'contextid' => $context->id], 'id ASC',
                'id, sourceid, targetid, entryid, questionid, version, versions, timecreated, timeused');
            $data['move'] = array_values(array_map(static function($record) {
                unset($record->id);
                $record->timecreated = transform::datetime($record->timecreated);
                $record->timeused = $record->timeused ? transform::datetime($record->timeused) : null;
                return $record;
            }, $moves));
            $statuses = $DB->get_records(self::STATUSES, ['userid' => $userid, 'contextid' => $context->id], 'id ASC',
                'id, categoryid, entryid, questionid, version, oldstatus, newstatus, timecreated, timeused');
            $data['status'] = array_values(array_map(static function($record) {
                unset($record->id);
                $record->timecreated = transform::datetime($record->timecreated);
                $record->timeused = $record->timeused ? transform::datetime($record->timeused) : null;
                return $record;
            }, $statuses));
            $tags = $DB->get_records(self::TAGS, ['userid' => $userid, 'contextid' => $context->id], 'id ASC',
                'id, categoryid, entryid, questionid, version, oldtags, newtags, timecreated, timeused');
            $data['tags'] = array_values(array_map(static function($record) {
                unset($record->id);
                $record->timecreated = transform::datetime($record->timecreated);
                $record->timeused = $record->timeused ? transform::datetime($record->timeused) : null;
                return $record;
            }, $tags));
            if ($data['preview'] || $data['import'] || $data['move'] || $data['status'] || $data['tags']) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_bosonanceqbank')], (object)$data);
            }
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        $DB->delete_records(self::TABLES['preview'], ['contextid' => $context->id]);
        $DB->set_field(self::TABLES['import'], 'userid', 0, ['contextid' => $context->id]);
        $DB->delete_records(self::MOVES, ['contextid' => $context->id, 'timeused' => 0]);
        $DB->set_field(self::MOVES, 'userid', 0, ['contextid' => $context->id]);
        $DB->delete_records(self::STATUSES, ['contextid' => $context->id, 'timeused' => 0]);
        $DB->set_field(self::STATUSES, 'userid', 0, ['contextid' => $context->id]);
        $DB->delete_records(self::TAGS, ['contextid' => $context->id, 'timeused' => 0]);
        $DB->set_field(self::TAGS, 'userid', 0, ['contextid' => $context->id]);
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            self::delete_users((int)$context->id, [$userid]);
        }
    }

    public static function delete_data_for_users(approved_userlist $userlist): void {
        self::delete_users((int)$userlist->get_context()->id, $userlist->get_userids());
    }

    private static function delete_users(int $contextid, array $userids): void {
        global $DB;

        if (!$userids) {
            return;
        }
        [$in, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['contextid'] = $contextid;
        $DB->delete_records_select(self::TABLES['preview'], "contextid = :contextid AND userid $in", $params);
        $DB->set_field_select(self::TABLES['import'], 'userid', 0, "contextid = :contextid AND userid $in", $params);
        $DB->delete_records_select(self::MOVES, "contextid = :contextid AND userid $in AND timeused = 0", $params);
        $DB->set_field_select(self::MOVES, 'userid', 0, "contextid = :contextid AND userid $in", $params);
        $DB->delete_records_select(self::STATUSES, "contextid = :contextid AND userid $in AND timeused = 0", $params);
        $DB->set_field_select(self::STATUSES, 'userid', 0, "contextid = :contextid AND userid $in", $params);
        $DB->delete_records_select(self::TAGS, "contextid = :contextid AND userid $in AND timeused = 0", $params);
        $DB->set_field_select(self::TAGS, 'userid', 0, "contextid = :contextid AND userid $in", $params);
    }
}
