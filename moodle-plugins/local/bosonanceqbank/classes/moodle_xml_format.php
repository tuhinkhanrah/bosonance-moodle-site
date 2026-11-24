<?php
namespace local_bosonanceqbank;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/question/format.php');
require_once($CFG->dirroot . '/question/format/xml/format.php');

/**
 * Moodle's XML importer, prevented from editing categories that already exist.
 */
class moodle_xml_format extends \qformat_xml {
    /** @var string[] Category paths that existed at preview time. */
    public array $existingpaths = [];

    protected function create_category_path($catpath, $lastcategoryinfo = null) {
        // Core would copy description/idnumber from the file into an existing empty category.
        if (in_array(trim((string)$catpath), $this->existingpaths, true)) {
            $lastcategoryinfo = null;
        }
        return parent::create_category_path($catpath, $lastcategoryinfo);
    }
}
