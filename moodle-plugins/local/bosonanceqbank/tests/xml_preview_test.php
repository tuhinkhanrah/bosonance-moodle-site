<?php
namespace local_bosonanceqbank;

defined('MOODLE_INTERNAL') || die();

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class xml_preview_test extends TestCase {
    public static function question_xml(): string {
        return '<question type="multichoice"><name><text>Example</text></name>'
            . '<questiontext format="html"><text><![CDATA[<p>Question?</p>]]></text></questiontext>'
            . '<single>true</single><answer fraction="100"><text>Yes</text></answer>'
            . '<answer fraction="0"><text>No</text></answer></question>';
    }

    public function test_parses_category_switches_without_importing_files(): void {
        $question = str_replace('</questiontext>', '<file name="image.png" encoding="base64">aW1hZ2U=</file></questiontext>',
            self::question_xml());
        $xml = '<quiz><question type="category"><category><text>$module$/top/bank/english</text></category></question>'
            . $question . '<question type="category"><category><text>$module$/top/bank/hindi</text></category></question>'
            . self::question_xml() . '</quiz>';
        $result = xml_preview::parse($xml);
        $this->assertSame(['$module$/top/bank/english', '$module$/top/bank/hindi'], $result['categories']);
        $this->assertSame('$module$/top/bank/english', $result['questions'][0]['path']);
        $this->assertSame('$module$/top/bank/hindi', $result['questions'][1]['path']);
        $this->assertSame('Example', $result['questions'][0]['name']);
        $this->assertArrayNotHasKey('questiontext', $result['questions'][0]);
    }

    public function test_accepts_unclassified_questions_for_explicit_destination(): void {
        $result = xml_preview::parse('<quiz>' . self::question_xml() . '</quiz>');
        $this->assertSame('', $result['questions'][0]['path']);
        $this->assertSame([], $result['categories']);
    }

    public function test_matching_uses_internal_type_and_allows_distractors(): void {
        $xml = '<quiz><question type="matching"><name><text>Pairs</text></name>'
            . '<questiontext><text>Match these</text></questiontext>'
            . '<subquestion><text>First</text><answer><text>A</text></answer></subquestion>'
            . '<subquestion><text>Second</text><answer><text>B</text></answer></subquestion>'
            . '<subquestion><text></text><answer><text>C</text></answer></subquestion></question></quiz>';
        $parsed = xml_preview::parse($xml);
        $this->assertSame('match', $parsed['questions'][0]['qtype']);
        $this->assertSame('Pairs', $parsed['questions'][0]['name']);
    }

    public function test_matching_rejects_missing_answer(): void {
        $xml = '<quiz><question type="matching"><name><text>Pairs</text></name>'
            . '<questiontext><text>Match these</text></questiontext>'
            . str_repeat('<subquestion><text>Prompt</text><answer><text></text></answer></subquestion>', 3)
            . '</question></quiz>';
        $this->expectException(\moodle_exception::class);
        xml_preview::parse($xml);
    }

    #[DataProvider('invalid_xml_provider')]
    public function test_rejects_invalid_xml(string $xml, string $code): void {
        try {
            xml_preview::parse($xml);
            $this->fail('Invalid XML must be rejected');
        } catch (\moodle_exception $exception) {
            $this->assertSame($code, $exception->errorcode);
        }
    }

    public static function invalid_xml_provider(): array {
        $question = self::question_xml();
        $category = '<question type="category"><category><text>%s</text></category></question>';
        return [
            'malformed' => ['<quiz>', 'xmlinvalid'],
            'ocr' => ['<ocr_result><source>chapter.pdf</source></ocr_result>', 'xmlinvalid'],
            'doctype' => ['<!DOCTYPE quiz [<!ENTITY x SYSTEM "file:///not-read">]><quiz>' . $question . '</quiz>', 'xmlinvalid'],
            'encoding' => ['<?xml version="1.0" encoding="ISO-8859-1"?><quiz>' . $question . '</quiz>', 'xmlinvalid'],
            'namespace' => ['<quiz xmlns="urn:other">' . $question . '</quiz>', 'xmlinvalid'],
            'empty' => ['<quiz/>', 'xmllimit'],
            'category only' => ['<quiz>' . sprintf($category, '$module$/top/bank') . '</quiz>', 'xmlquestion'],
            'course context' => ['<quiz>' . sprintf($category, '$course$/top/bank') . $question . '</quiz>', 'xmlpath'],
            'traversal' => ['<quiz>' . sprintf($category, '$module$/top/../bank') . $question . '</quiz>', 'xmlpath'],
            'empty segment' => ['<quiz>' . sprintf($category, '$module$/top/bank//english') . $question . '</quiz>', 'xmlpath'],
            'unsupported type' => ['<quiz><question type="essay"/></quiz>', 'xmltype'],
            'missing name' => ['<quiz>' . str_replace('<name><text>Example</text></name>', '', $question) . '</quiz>', 'xmlquestion'],
            'bad fraction' => ['<quiz>' . str_replace('fraction="100"', 'fraction="101"', $question) . '</quiz>', 'xmlquestion'],
            'two correct' => ['<quiz>' . str_replace('fraction="0"', 'fraction="100"', $question) . '</quiz>', 'xmlquestion'],
            'invalid file' => ['<quiz>' . str_replace('</questiontext>',
                '<file encoding="base64">!</file></questiontext>', $question) . '</quiz>', 'xmlquestion'],
        ];
    }

    public function test_rejects_oversized_input(): void {
        $this->expectException(\moodle_exception::class);
        xml_preview::parse(str_repeat(' ', xml_preview::MAX_BYTES + 1));
    }

    public function test_rejects_too_many_records(): void {
        $this->expectException(\moodle_exception::class);
        xml_preview::parse('<quiz>' . str_repeat(self::question_xml(), xml_preview::MAX_RECORDS + 1) . '</quiz>');
    }

    public function test_accepts_exact_record_limit(): void {
        $parsed = xml_preview::parse('<quiz>' . str_repeat(self::question_xml(), xml_preview::MAX_RECORDS) . '</quiz>');
        $this->assertCount(xml_preview::MAX_RECORDS, $parsed['questions']);
    }
}
