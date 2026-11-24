<?php
namespace local_bosonanceqbank\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

class export_xml extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Bank module context ID'),
            'questionids' => new external_multiple_structure(new external_value(PARAM_INT, 'Explicit version-specific ID')),
        ]);
    }

    public static function execute(int $contextid, array $questionids): array {
        global $CFG, $DB;

        self::validate_parameters(self::execute_parameters(), compact('contextid', 'questionids'));
        $context = access::bank($contextid);
        if (!$questionids || count($questionids) > 100 || count(array_unique($questionids)) !== count($questionids)
                || min($questionids) < 1) {
            throw new \invalid_parameter_exception(get_string('invalidselection', 'local_bosonanceqbank'));
        }
        require_once($CFG->dirroot . '/question/format/xml/format.php');
        $questions = [];
        foreach ($questionids as $questionid) {
            $record = access::question($contextid, (int)$questionid);
            if ($record->status === 'hidden') {
                throw new \moodle_exception('hiddenexport', 'local_bosonanceqbank');
            }
            $questions[] = \question_bank::load_question_data((int)$questionid);
        }
        $cm = get_coursemodule_from_id(null, $context->instanceid, 0, false, MUST_EXIST);
        $format = new \qformat_xml();
        $format->setContexts([$context]);
        $format->setCourse($DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST));
        $format->setQuestions($questions);
        $format->setCattofile(true);
        $format->setContexttofile(true);
        if (!$format->exportpreprocess()) {
            throw new \moodle_exception('exportfailed', 'local_bosonanceqbank');
        }
        $xml = $format->exportprocess(true);
        $document = new \DOMDocument();
        if (strlen($xml) > \local_bosonanceqbank\xml_preview::MAX_BYTES || !$document->loadXML($xml, LIBXML_NONET)) {
            throw new \moodle_exception('exportfailed', 'local_bosonanceqbank');
        }
        $xpath = new \DOMXPath($document);
        if ($xpath->query('/quiz/question[@type != "category"]')->length !== count($questionids)) {
            throw new \moodle_exception('exportfailed', 'local_bosonanceqbank');
        }
        return ['xml' => $xml, 'sha256' => hash('sha256', $xml), 'questioncount' => count($questions)];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'xml' => new external_value(PARAM_RAW, 'Moodle XML with category paths and embedded files'),
            'sha256' => new external_value(PARAM_ALPHANUM, 'SHA-256 of XML bytes'),
            'questioncount' => new external_value(PARAM_INT, 'Exported questions'),
        ]);
    }
}
