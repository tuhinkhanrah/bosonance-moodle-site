<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_bosonanceqbank_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();
    if ($oldversion < 2026112201) {
        foreach (['local_bosonanceqbank_preview', 'local_bosonanceqbank_import'] as $table) {
            if (!$dbman->table_exists($table)) {
                $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', $table);
            }
        }
        upgrade_plugin_savepoint(true, 2026112201, 'local', 'bosonanceqbank');
    }
    if ($oldversion < 2026112202) {
        if (!$dbman->table_exists('local_bosonanceqbank_move')) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_bosonanceqbank_move');
        }
        upgrade_plugin_savepoint(true, 2026112202, 'local', 'bosonanceqbank');
    }
    if ($oldversion < 2026112400) {
        if (!$dbman->table_exists('local_bosonanceqbank_status')) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_bosonanceqbank_status');
        }
        upgrade_plugin_savepoint(true, 2026112400, 'local', 'bosonanceqbank');
    }
    if ($oldversion < 2026112401) {
        if (!$dbman->table_exists('local_bosonanceqbank_tags')) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_bosonanceqbank_tags');
        }
        upgrade_plugin_savepoint(true, 2026112401, 'local', 'bosonanceqbank');
    }
    if ($oldversion < 2026112501) {
        if (!$dbman->table_exists('local_bosonanceqbank_category')) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_bosonanceqbank_category');
        }
        upgrade_plugin_savepoint(true, 2026112501, 'local', 'bosonanceqbank');
    }
    if ($oldversion < 2026112502) {
        $DB->set_field('local_bosonanceqbank_import', 'filename', '', ['userid' => 0]);
        upgrade_plugin_savepoint(true, 2026112502, 'local', 'bosonanceqbank');
    }
    if ($oldversion < 2026112504) {
        if (!$dbman->table_exists('local_bosonanceqbank_delete')) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_bosonanceqbank_delete');
        }
        upgrade_plugin_savepoint(true, 2026112504, 'local', 'bosonanceqbank');
    }
    return true;
}
