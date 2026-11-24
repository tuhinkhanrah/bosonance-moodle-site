<?php
namespace local_bosonanceblocks\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;

class access extends external_api {
    public static function require_admin(): void {
        self::validate_context(\context_system::instance());
        if (!is_siteadmin()) {
            throw new \moodle_exception('adminrequired', 'local_bosonanceblocks');
        }
    }
}
