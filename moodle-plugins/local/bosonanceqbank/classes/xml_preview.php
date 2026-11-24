<?php
namespace local_bosonanceqbank;

defined('MOODLE_INTERNAL') || die();

/**
 * Structural inspection only: Moodle's readquestions() can stage embedded files.
 */
class xml_preview {
    public const MAX_BYTES = 10485760;
    public const MAX_RECORDS = 1000;

    public static function parse(string $xml): array {
        if (strlen($xml) > self::MAX_BYTES) {
            throw new \moodle_exception('xmllimit', 'local_bosonanceqbank');
        }
        if (!preg_match('//u', $xml) || preg_match('/<!DOCTYPE/i', $xml)
                || (preg_match('/<\?xml[^?]*encoding\s*=\s*["\']([^"\']+)/i', $xml, $encoding)
                    && strcasecmp($encoding[1], 'UTF-8') !== 0)) {
            throw new \moodle_exception('xmlinvalid', 'local_bosonanceqbank');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            if (!$document->loadXML($xml, LIBXML_NONET) || $document->doctype
                    || !$document->documentElement || $document->documentElement->tagName !== 'quiz'
                    || $document->documentElement->namespaceURI) {
                throw new \moodle_exception('xmlinvalid', 'local_bosonanceqbank');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new \DOMXPath($document);
        $records = $xpath->query('/quiz/*');
        if ($records->length < 1 || $records->length > self::MAX_RECORDS) {
            throw new \moodle_exception('xmllimit', 'local_bosonanceqbank');
        }
        $categories = [];
        $questions = [];
        $currentpath = '';
        foreach ($records as $record) {
            if ($record->tagName !== 'question' || $record->namespaceURI) {
                throw new \moodle_exception('xmlinvalid', 'local_bosonanceqbank');
            }
            $type = $record->getAttribute('type');
            if ($type === 'category') {
                $currentpath = self::text($xpath, $record, 'category/text');
                $segments = explode('/', $currentpath);
                if (count($segments) < 3 || $segments[0] !== '$module$' || $segments[1] !== 'top') {
                    throw new \moodle_exception('xmlpath', 'local_bosonanceqbank');
                }
                foreach (array_slice($segments, 2) as $segment) {
                    if ($segment === '' || $segment === '.' || $segment === '..'
                            || trim($segment) !== $segment || str_contains($segment, '\\')
                            || preg_match('/[\x00-\x1f\x7f]/', $segment)
                            || \core_text::strlen($segment) > 255) {
                        throw new \moodle_exception('xmlpath', 'local_bosonanceqbank');
                    }
                }
                $categories[$currentpath] = $currentpath;
                continue;
            }
            if (!in_array($type, ['multichoice', 'matching'], true)) {
                throw new \moodle_exception('xmltype', 'local_bosonanceqbank');
            }
            $name = self::text($xpath, $record, 'name/text');
            if ($name === '' || \core_text::strlen($name) > 255
                    || self::text($xpath, $record, 'questiontext/text') === '') {
                throw new \moodle_exception('xmlquestion', 'local_bosonanceqbank');
            }
            if ($type === 'matching') {
                self::validate_matching($xpath, $record);
            } else {
                self::validate_multichoice($xpath, $record);
            }
            foreach ($xpath->query('.//file', $record) as $file) {
                if ($file->getAttribute('encoding') !== 'base64'
                        || base64_decode(preg_replace('/\s+/', '', $file->textContent), true) === false) {
                    throw new \moodle_exception('xmlquestion', 'local_bosonanceqbank');
                }
            }
            $questions[] = ['name' => $name, 'qtype' => $type === 'matching' ? 'match' : $type, 'path' => $currentpath];
        }
        if (!$questions) {
            throw new \moodle_exception('xmlquestion', 'local_bosonanceqbank');
        }
        return ['categories' => array_values($categories), 'questions' => $questions];
    }

    private static function text(\DOMXPath $xpath, \DOMElement $node, string $path): string {
        $nodes = $xpath->query($path, $node);
        if ($nodes->length !== 1) {
            throw new \moodle_exception('xmlquestion', 'local_bosonanceqbank');
        }
        return trim($nodes->item(0)->textContent);
    }

    private static function validate_multichoice(\DOMXPath $xpath, \DOMElement $record): void {
        $single = self::text($xpath, $record, 'single');
        if (!in_array($single, ['true', 'false', '1', '0'], true)) {
            throw new \moodle_exception('xmlquestion', 'local_bosonanceqbank');
        }
        $answers = $xpath->query('answer', $record);
        $positive = 0.0;
        $correct = 0;
        if ($answers->length < 2) {
            throw new \moodle_exception('xmlquestion', 'local_bosonanceqbank');
        }
        foreach ($answers as $answer) {
            $fraction = $answer->getAttribute('fraction');
            if (!is_numeric($fraction) || !is_finite((float)$fraction)
                    || (float)$fraction < -100 || (float)$fraction > 100
                    || self::text($xpath, $answer, 'text') === '') {
                throw new \moodle_exception('xmlquestion', 'local_bosonanceqbank');
            }
            $positive += max(0, (float)$fraction);
            $correct += (float)$fraction === 100.0 ? 1 : 0;
        }
        if ((in_array($single, ['true', '1'], true) && ($correct !== 1 || abs($positive - 100) > 0.0001))
                || (!in_array($single, ['true', '1'], true) && abs($positive - 100) > 0.0001)) {
            throw new \moodle_exception('xmlquestion', 'local_bosonanceqbank');
        }
    }

    private static function validate_matching(\DOMXPath $xpath, \DOMElement $record): void {
        $subquestions = $xpath->query('subquestion', $record);
        $prompts = 0;
        if ($subquestions->length < 3) {
            throw new \moodle_exception('xmlquestion', 'local_bosonanceqbank');
        }
        foreach ($subquestions as $subquestion) {
            $prompts += self::text($xpath, $subquestion, 'text') !== '' ? 1 : 0;
            if (self::text($xpath, $subquestion, 'answer/text') === '') {
                throw new \moodle_exception('xmlquestion', 'local_bosonanceqbank');
            }
        }
        if ($prompts < 2) {
            throw new \moodle_exception('xmlquestion', 'local_bosonanceqbank');
        }
    }
}
