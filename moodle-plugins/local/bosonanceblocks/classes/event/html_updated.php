<?php
namespace local_bosonanceblocks\event;

defined('MOODLE_INTERNAL') || die();

class html_updated extends \core\event\base {
    protected function init(): void {
        $this->data['objecttable'] = 'block_instances';
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    public static function get_name(): string {
        return get_string('eventhtmlupdated', 'local_bosonanceblocks');
    }

    public function get_description(): string {
        return "The user with id '{$this->userid}' updated HTML block instance '{$this->objectid}' via web service.";
    }

    public static function get_objectid_mapping(): array {
        return ['db' => 'block_instances', 'restore' => self::NOT_MAPPED];
    }
}
