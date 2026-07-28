<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Hryvinskyi\EmailTemplateEditor\Controller\Adminhtml\SampleData\LoadList;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\ConfigPathWritability;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Holds i18n/en_US.csv to everything the module actually says out loud.
 *
 * The dictionary is written and edited by hand, and this is what keeps that honest. The phrase
 * collector shipped with Magento cannot produce it, for two reasons that are worth knowing before
 * anybody reaches for it again:
 *
 * - it reads JavaScript a line at a time, and joins concatenated pieces only within the line it is
 *   holding, so a phrase written across several lines is dropped from its output without a word.
 *   The browser's own dictionary joins the same pieces across the whole file, so the phrase is
 *   looked up at runtime and simply never found. The sweep here joins across lines, which is the
 *   point of it: a phrase the collector would lose is still required to be present;
 * - it reads XML only where a translate attribute points it at something, and the knowledge base is
 *   plain text nodes with no such attribute anywhere, so none of that prose is visible to it at all.
 *   Those rows are read straight out of the document by the sweep below, normalised exactly the way
 *   the converter normalises them, because that is the form the phrase reaches a renderer in.
 *
 * The sweep therefore covers, and the dictionary has to match exactly: every literal handed to __()
 * in PHP and to $t() in JavaScript, every i18n binding and $t() call in the Knockout templates,
 * every configuration value an attribute marks as translatable, the administration titles the
 * platform translates on its own account without such an attribute, every statement in the knowledge
 * base, and the tables of prose that reach __() through a variable rather than as a literal.
 *
 * The match is required in both directions. A phrase with no row is a string that will stay English
 * in every locale; a row with no phrase is prose somebody deleted and the dictionary still carries,
 * which is how a translator ends up working on sentences nobody will ever read.
 */
class I18nCoverageTest extends TestCase
{
    /**
     * Directories holding the module's PHP classes, relative to the module root
     */
    private const PHP_DIRECTORIES = ['Api', 'Block', 'Controller', 'Model', 'Plugin', 'Setup'];

    /**
     * Directory holding the module's PHP templates, relative to the module root
     *
     * Swept although the one template there says nothing at present. A template is the likeliest
     * place for the next piece of prose to be written, and a sweep that only covers the classes
     * would let it through without a row and without a failure.
     */
    private const PHTML_DIRECTORY = 'view';

    /**
     * Directory holding the module's JavaScript, relative to the module root
     */
    private const JS_DIRECTORY = 'view/adminhtml/web/js';

    /**
     * Directory holding the module's Knockout templates, relative to the module root
     */
    private const TEMPLATE_DIRECTORY = 'view/adminhtml/web/template';

    /**
     * Directories holding XML configuration, relative to the module root
     */
    private const CONFIG_DIRECTORIES = ['etc', 'view/adminhtml/layout'];

    /**
     * The knowledge base document, relative to the module root
     */
    private const KNOWLEDGE_FILE = 'etc/email_variables.xml';

    /**
     * The dictionary, relative to the module root
     */
    private const DICTIONARY_FILE = 'i18n/en_US.csv';

    /**
     * What separates the two columns of the dictionary
     */
    private const CSV_SEPARATOR = ',';

    /**
     * What encloses a column of the dictionary that needs it
     */
    private const CSV_ENCLOSURE = '"';

    /**
     * What the reader treats as an escape inside an enclosed column
     *
     * Spelled out rather than left to the default, which is on its way to changing. The point of
     * reading the file the way the platform reads it is that a quoting mistake fails the suite
     * instead of becoming a phrase nothing matches at runtime, and that only holds while the two
     * agree - which they stop doing the moment either side takes a default it did not state.
     */
    private const CSV_ESCAPE = '\\';

    /**
     * Elements of the knowledge base whose text an administrator reads
     */
    private const KNOWLEDGE_PATHS = [
        '//variable/title',
        '//variable/summary',
        '//variable/origin',
        '//variable/caveat',
        '//variable/affordance/step',
        '//variable/affordance/option',
        '//variable/affordance/@label',
    ];

    /**
     * Titles the platform translates although nothing in the document says they are translatable
     *
     * An access resource title is translated where the role tree is built, and a menu entry's title
     * where the anchor is written; neither carries a translate attribute, so neither is visible to
     * the collector. Both are read by an administrator, so both need a row.
     *
     * @var array<string, string> File relative to the module root, to the attributes to read
     */
    private const UNMARKED_TITLE_PATHS = [
        'etc/acl.xml' => '//resource/@title',
        'etc/adminhtml/menu.xml' => '//add/@title',
    ];

    /**
     * Where a table of prose reaches __() through a variable instead of as a literal
     *
     * A phrase written as a literal is found by reading the call. These are not: the call passes a
     * variable, and the sentences live in a table somewhere else. They are still shown to an
     * administrator, so they still need rows, and the only way to find them is to know where they
     * are - which is what this list is. It is checked against the module: a call passing something
     * other than a literal from a file this test does not expect fails the suite by name, so a table
     * added later cannot go undocumented quietly.
     *
     * @var array<class-string, string[]> Class to the constants holding its prose
     */
    private const INDIRECT_PHRASE_TABLES = [
        ConfigPathWritability::class => ['DEFAULT_RENDERED_DIFFERS_FROM_STORED'],
        LoadList::class => ['GROUP_LABELS'],
    ];

    /**
     * How many calls in each file hand __() something other than a literal
     *
     * All but one of them read from a table above; the exception translates merchant content rather
     * than module prose, and is named in the note beside it. Counted per file rather than pinned to a line,
     * because a line moves whenever anything above it is reworded and that would fail the suite for
     * a change that altered nothing; a count still moves the moment a call is added or removed.
     *
     * @var array<string, int>
     */
    private const INDIRECT_PHRASE_CALLS = [
        'Controller/Adminhtml/SampleData/LoadList.php' => 1,
        'Model/Knowledge/ConfigPathWritability.php' => 1,
        // Translates the message written into a {{trans}} directive, which belongs to the merchant's
        // templates and never to this module. It has no row here and must not gain one: the store's
        // own language pack is what answers it, and that is the whole point of showing the value.
        'Model/Knowledge/Value/TranslatedMessageValueStrategy.php' => 1,
        // The knowledge base's own prose - title, summary, origin explanation, caveats, and an
        // affordance's label and steps. Its sentences live in etc/email_variables.xml and are swept
        // from there, which is the only place they exist as text: by the time they reach this class
        // they are already values read out of merged configuration.
        'Model/Knowledge/XmlKnowledgeProvider.php' => 9,
    ];

    /**
     * Every literal handed to __() has a row
     *
     * @return void
     */
    public function testEveryPhpPhraseHasARow(): void
    {
        $this->assertPhrasesAreCovered($this->phpPhrases(), 'PHP');
    }

    /**
     * Every literal handed to $t() has a row, however many lines it was written across
     *
     * @return void
     */
    public function testEveryJavaScriptPhraseHasARow(): void
    {
        $this->assertPhrasesAreCovered($this->javaScriptPhrases(), 'JavaScript');
    }

    /**
     * Every phrase written into a Knockout template has a row
     *
     * @return void
     */
    public function testEveryTemplateBindingHasARow(): void
    {
        $this->assertPhrasesAreCovered($this->templatePhrases(), 'Knockout template');
    }

    /**
     * Every configuration value the platform translates has a row
     *
     * @return void
     */
    public function testEveryTranslatedConfigurationValueHasARow(): void
    {
        $this->assertPhrasesAreCovered($this->configurationPhrases(), 'XML configuration');
    }

    /**
     * Every statement the knowledge base makes has a row
     *
     * @return void
     */
    public function testEveryKnowledgeBaseStatementHasARow(): void
    {
        $this->assertPhrasesAreCovered($this->knowledgePhrases(), 'knowledge base');
    }

    /**
     * Every sentence reaching __() from a table rather than as a literal has a row
     *
     * @return void
     */
    public function testEveryIndirectPhraseHasARow(): void
    {
        $this->assertPhrasesAreCovered($this->indirectPhrases(), 'indirectly translated table');
    }

    /**
     * Nothing else in the module hands __() something other than a literal
     *
     * A new one means a new set of sentences nobody can find by reading the calls, so it has to be
     * declared here and its table swept, or the dictionary quietly stops covering the module.
     *
     * @return void
     */
    public function testNoUndeclaredCallTranslatesSomethingOtherThanALiteral(): void
    {
        $found = $this->indirectPhraseCallSites();
        $declared = self::INDIRECT_PHRASE_CALLS;
        ksort($found);
        ksort($declared);

        $this->assertSame(
            $declared,
            $found,
            'A call passes __() something that is not a literal from a place this test does not know '
            . 'about. Add the table it reads from, so its sentences are swept into the dictionary.'
        );
    }

    /**
     * The dictionary carries nothing the module no longer says
     *
     * @return void
     */
    public function testNoRowIsLeftOverFromAPhraseThatIsGone(): void
    {
        $swept = array_merge(
            $this->phpPhrases(),
            $this->javaScriptPhrases(),
            $this->templatePhrases(),
            $this->configurationPhrases(),
            $this->knowledgePhrases(),
            $this->indirectPhrases()
        );

        $leftOver = array_values(array_diff(array_keys($this->dictionary()), $swept));
        sort($leftOver);

        $this->assertSame(
            [],
            $leftOver,
            'The dictionary carries rows for phrases the module no longer contains. Remove them, so '
            . 'that nobody translates sentences that are never shown.'
        );
    }

    /**
     * The file is a dictionary: two columns, each key once, nothing left blank
     *
     * @return void
     */
    public function testTheDictionaryIsWellFormed(): void
    {
        $seen = [];
        $repeated = [];
        $malformed = [];
        $blank = [];

        $handle = fopen($this->modulePath(self::DICTIONARY_FILE), 'r');
        $this->assertIsResource($handle, 'The dictionary could not be opened.');

        $line = 0;

        while (($row = fgetcsv($handle, 0, self::CSV_SEPARATOR, self::CSV_ENCLOSURE, self::CSV_ESCAPE)) !== false) {
            $line++;

            if ($row === [null]) {
                continue;
            }

            if (count($row) !== 2) {
                $malformed[] = $line;
                continue;
            }

            [$phrase, $translation] = $row;

            if (trim((string)$phrase) === '' || trim((string)$translation) === '') {
                $blank[] = $line;
                continue;
            }

            if (isset($seen[$phrase])) {
                $repeated[] = $phrase;
            }

            $seen[$phrase] = true;
        }

        fclose($handle);

        $this->assertSame([], $malformed, 'These lines of the dictionary do not hold exactly two columns.');
        $this->assertSame([], $blank, 'These lines of the dictionary leave a phrase or its translation empty.');
        $this->assertSame([], $repeated, 'These phrases appear in the dictionary more than once.');
        $this->assertNotSame([], $seen, 'The dictionary is empty.');
    }

    /**
     * The sweep joins a phrase written across lines, which is the whole reason it exists
     *
     * Written against a fragment rather than against the module so that it keeps testing the
     * capability after the last multi-line phrase in the module is reworded away.
     *
     * @return void
     */
    public function testTheSweepJoinsAPhraseWrittenAcrossLines(): void
    {
        $fragment = <<<'JS'
        this.message($t(
            'One half of a sentence ' +
            'and the other half.'
        ));
        JS;

        $this->assertSame(
            ['One half of a sentence and the other half.'],
            $this->collectCallLiterals($fragment, '$t(', '+')
        );
    }

    /**
     * Fail with the phrases that have no row
     *
     * @param string[] $phrases Phrases swept from one kind of source
     * @param string $source What was swept, so a failure says where to look
     * @return void
     */
    private function assertPhrasesAreCovered(array $phrases, string $source): void
    {
        $this->assertNotSame([], $phrases, sprintf('Nothing was swept from %s, which cannot be right.', $source));

        $dictionary = $this->dictionary();
        $missing = [];

        foreach ($phrases as $phrase) {
            if (!isset($dictionary[$phrase])) {
                $missing[] = $phrase;
            }
        }

        sort($missing);

        $this->assertSame(
            [],
            $missing,
            sprintf('These %s phrases have no row in the dictionary.', $source)
        );
    }

    /**
     * The dictionary, keyed by phrase
     *
     * Read the way the platform reads it, so that a quoting mistake fails here rather than at
     * runtime.
     *
     * @return array<string, string>
     */
    private function dictionary(): array
    {
        $rows = [];
        $handle = fopen($this->modulePath(self::DICTIONARY_FILE), 'r');

        if ($handle === false) {
            return $rows;
        }

        while (($row = fgetcsv($handle, 0, self::CSV_SEPARATOR, self::CSV_ENCLOSURE, self::CSV_ESCAPE)) !== false) {
            if (isset($row[0], $row[1])) {
                $rows[(string)$row[0]] = (string)$row[1];
            }
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Every literal handed to __() anywhere in the module's PHP, classes and templates alike
     *
     * @return string[]
     */
    private function phpPhrases(): array
    {
        $phrases = [];

        foreach ($this->phpFiles() as $file) {
            foreach ($this->collectPhpLiterals(file_get_contents($file->getPathname()))['phrases'] as $phrase) {
                $phrases[] = $phrase;
            }
        }

        return $this->unique($phrases);
    }

    /**
     * Every literal handed to $t() anywhere in the module's JavaScript
     *
     * @return string[]
     */
    private function javaScriptPhrases(): array
    {
        $phrases = [];

        foreach ($this->sourceFiles([self::JS_DIRECTORY], 'js') as $file) {
            $content = file_get_contents($file->getPathname());

            foreach ($this->collectCallLiterals($content, '$t(', '+') as $phrase) {
                $phrases[] = $phrase;
            }
        }

        return $this->unique($phrases);
    }

    /**
     * Every phrase written into a Knockout template
     *
     * Both forms are swept: the binding that translates an element's text, and the call that
     * translates a value passed to another binding.
     *
     * @return string[]
     */
    private function templatePhrases(): array
    {
        $phrases = [];

        foreach ($this->sourceFiles([self::TEMPLATE_DIRECTORY], 'html') as $file) {
            $content = file_get_contents($file->getPathname());

            foreach ($this->collectCallLiterals($content, '$t(', '+') as $phrase) {
                $phrases[] = $phrase;
            }

            foreach ($this->collectBindingLiterals($content) as $phrase) {
                $phrases[] = $phrase;
            }
        }

        return $this->unique($phrases);
    }

    /**
     * Every configuration value the platform translates
     *
     * The rules for what an attribute marks are the platform's own: translate="true" marks the
     * element's own text, and anything else names the children and attributes to read.
     *
     * @return string[]
     */
    private function configurationPhrases(): array
    {
        $phrases = [];

        foreach ($this->sourceFiles(self::CONFIG_DIRECTORIES, 'xml') as $file) {
            $document = $this->readXml($file->getPathname());
            $xpath = new DOMXPath($document);

            foreach ($xpath->query('//*[@translate or @translatable]') as $node) {
                if (!$node instanceof DOMElement) {
                    continue;
                }

                $marker = $node->getAttribute('translate') . $node->getAttribute('translatable');

                if ($marker === 'true') {
                    $phrases[] = $this->normalise($node->textContent);
                    continue;
                }

                foreach ($this->markedNames($node->getAttribute('translate')) as $name) {
                    foreach ($this->childElements($node, $name) as $child) {
                        $phrases[] = $this->normalise($child->textContent);
                    }

                    if ($node->hasAttribute($name)) {
                        $phrases[] = $this->normalise($node->getAttribute($name));
                    }
                }
            }
        }

        foreach (self::UNMARKED_TITLE_PATHS as $file => $query) {
            $xpath = new DOMXPath($this->readXml($this->modulePath($file)));

            foreach ($xpath->query($query) as $attribute) {
                $phrases[] = $this->normalise($attribute->nodeValue);
            }
        }

        return $this->unique(array_filter($phrases, static fn (string $phrase): bool => $phrase !== ''));
    }

    /**
     * Every statement the knowledge base makes
     *
     * Collapsed the way the converter collapses it, because that is the form the phrase is in by the
     * time anything could translate it: the document wraps prose over indented lines, and the layout
     * of the file is not part of the sentence.
     *
     * @return string[]
     */
    private function knowledgePhrases(): array
    {
        $phrases = [];
        $xpath = new DOMXPath($this->readXml($this->modulePath(self::KNOWLEDGE_FILE)));

        foreach (self::KNOWLEDGE_PATHS as $query) {
            foreach ($xpath->query($query) as $node) {
                $phrases[] = $this->normalise($node->textContent);
            }
        }

        return $this->unique(array_filter($phrases, static fn (string $phrase): bool => $phrase !== ''));
    }

    /**
     * Every sentence in a table that reaches __() through a variable
     *
     * @return string[]
     */
    private function indirectPhrases(): array
    {
        $phrases = [];

        foreach (self::INDIRECT_PHRASE_TABLES as $class => $constants) {
            $reflection = new ReflectionClass($class);

            foreach ($constants as $constant) {
                $table = $reflection->getConstant($constant);

                $this->assertIsArray(
                    $table,
                    sprintf('%s::%s no longer holds a table of prose.', $class, $constant)
                );

                foreach ($table as $value) {
                    $phrases[] = (string)$value;
                }
            }
        }

        return $this->unique($phrases);
    }

    /**
     * How many calls in each file hand __() something other than a literal
     *
     * @return array<string, int>
     */
    private function indirectPhraseCallSites(): array
    {
        $sites = [];
        $root = $this->modulePath('');

        foreach ($this->phpFiles() as $file) {
            $calls = $this->collectPhpLiterals(file_get_contents($file->getPathname()))['indirect'];

            if ($calls > 0) {
                $sites[substr($file->getPathname(), strlen($root))] = $calls;
            }
        }

        return $sites;
    }

    /**
     * Read the literals handed to __(), and note the calls that hand it something else
     *
     * Tokenised rather than matched, because a phrase is routinely written as several single-quoted
     * pieces joined with dots across as many lines as it takes to stay readable, and the pieces are
     * one phrase by the time the call is made.
     *
     * @param string $code Contents of a PHP file
     * @return array{phrases: string[], indirect: int} The literals, and how many calls handed it
     *         something else
     */
    private function collectPhpLiterals(string $code): array
    {
        $tokens = token_get_all($code);
        $count = count($tokens);
        $phrases = [];
        $indirect = 0;

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];

            if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== '__') {
                continue;
            }

            if ($this->isMemberAccess($tokens, $index)) {
                continue;
            }

            $cursor = $this->skipTrivia($tokens, $index + 1);

            if ($cursor >= $count || $tokens[$cursor] !== '(') {
                continue;
            }

            $pieces = [];
            $expectPiece = true;
            $cursor = $this->skipTrivia($tokens, $cursor + 1);

            while ($cursor < $count) {
                $current = $tokens[$cursor];

                if ($expectPiece) {
                    if (!is_array($current)
                        || $current[0] !== T_CONSTANT_ENCAPSED_STRING
                        || $current[1][0] !== "'"
                    ) {
                        $pieces = [];
                        break;
                    }

                    $pieces[] = $this->unescapePhpLiteral(substr($current[1], 1, -1));
                    $expectPiece = false;
                    $cursor = $this->skipTrivia($tokens, $cursor + 1);
                    continue;
                }

                if ($current !== '.') {
                    break;
                }

                $expectPiece = true;
                $cursor = $this->skipTrivia($tokens, $cursor + 1);
            }

            if ($pieces === []) {
                $indirect++;
                continue;
            }

            $phrases[] = implode('', $pieces);
        }

        return ['phrases' => $phrases, 'indirect' => $indirect];
    }

    /**
     * Read the literals handed to a call, joining the pieces it was written in
     *
     * The pieces are joined across lines on purpose. The collector shipped with the platform holds
     * one line at a time and loses everything written across more than one; the browser's own
     * dictionary joins them, so those phrases are looked up at runtime and have to be in the file.
     *
     * @param string $content Contents of a JavaScript file or a template
     * @param string $call The opening of the call, up to and including its bracket
     * @param string $joiner The operator the pieces of a phrase are joined with
     * @return string[]
     */
    private function collectCallLiterals(string $content, string $call, string $joiner): array
    {
        $phrases = [];
        $length = strlen($content);
        $callLength = strlen($call);
        $offset = 0;

        while (($position = strpos($content, $call, $offset)) !== false) {
            $offset = $position + $callLength;

            // A call, not the tail of a longer name that happens to end the same way.
            if ($position > 0 && preg_match('/[A-Za-z0-9_$.]/', $content[$position - 1]) === 1) {
                continue;
            }

            $cursor = $offset;
            $pieces = [];
            $expectPiece = true;

            while ($cursor < $length) {
                $character = $content[$cursor];

                if (trim($character) === '') {
                    $cursor++;
                    continue;
                }

                if ($expectPiece) {
                    if ($character !== "'") {
                        $pieces = [];
                        break;
                    }

                    [$piece, $cursor] = $this->readQuotedPiece($content, $cursor);
                    $pieces[] = $piece;
                    $expectPiece = false;
                    continue;
                }

                if ($character !== $joiner) {
                    break;
                }

                $expectPiece = true;
                $cursor++;
            }

            if ($pieces !== []) {
                $phrases[] = implode('', $pieces);
            }
        }

        return $phrases;
    }

    /**
     * Read the phrases bound to an element's own text in a Knockout template
     *
     * @param string $content Contents of a template
     * @return string[]
     */
    private function collectBindingLiterals(string $content): array
    {
        $phrases = [];
        $matches = [];

        preg_match_all('/i18n:\s*\'((?:[^\'\\\\]|\\\\.)*)\'/', $content, $matches);

        foreach ($matches[1] as $literal) {
            $phrases[] = $this->unescapeBrowserLiteral($literal);
        }

        return $phrases;
    }

    /**
     * Read one single-quoted piece, starting at its opening quote
     *
     * @param string $content Contents being read
     * @param int $start Index of the opening quote
     * @return array{0: string, 1: int} The piece, and the index just past its closing quote
     */
    private function readQuotedPiece(string $content, int $start): array
    {
        $length = strlen($content);
        $cursor = $start + 1;
        $literal = '';

        while ($cursor < $length) {
            if ($content[$cursor] === '\\' && $cursor + 1 < $length) {
                $literal .= $content[$cursor] . $content[$cursor + 1];
                $cursor += 2;
                continue;
            }

            if ($content[$cursor] === "'") {
                break;
            }

            $literal .= $content[$cursor];
            $cursor++;
        }

        return [$this->unescapeBrowserLiteral($literal), $cursor + 1];
    }

    /**
     * Undo the escaping of a piece written for the browser
     *
     * Only the quotes are undone, which is exactly what the browser's dictionary does when it builds
     * its keys. Undoing more would produce a key nothing ever looks up.
     *
     * @param string $literal The bytes between the quotes
     * @return string
     */
    private function unescapeBrowserLiteral(string $literal): string
    {
        return str_replace(["\\'", '\\"'], ["'", '"'], $literal);
    }

    /**
     * Undo the escaping of a single-quoted PHP literal
     *
     * Single quotes recognise two escapes and pass everything else through untouched, so a phrase
     * containing a backslash reaches the dictionary with that backslash in it.
     *
     * @param string $literal The bytes between the quotes
     * @return string
     */
    private function unescapePhpLiteral(string $literal): string
    {
        return preg_replace('/\\\\([\\\\\'])/', '$1', $literal);
    }

    /**
     * Whether the name at this position is a member rather than the translation function
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens Tokenised file
     * @param int $index Index of the name
     * @return bool
     */
    private function isMemberAccess(array $tokens, int $index): bool
    {
        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            $token = $tokens[$cursor];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($token)
                && in_array($token[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);
        }

        return false;
    }

    /**
     * The index of the next token that is not whitespace or a comment
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens Tokenised file
     * @param int $index Index to start from
     * @return int
     */
    private function skipTrivia(array $tokens, int $index): int
    {
        $count = count($tokens);

        while ($index < $count) {
            $token = $tokens[$index];

            if (!is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                break;
            }

            $index++;
        }

        return $index;
    }

    /**
     * The names a translate attribute points at
     *
     * @param string $marker Value of the attribute
     * @return string[]
     */
    private function markedNames(string $marker): array
    {
        $separator = str_contains($marker, ' ') ? ' ' : ',';

        return array_values(array_filter(array_map('trim', explode($separator, $marker))));
    }

    /**
     * Every direct child element of the given name
     *
     * @param DOMElement $parent Element to look under
     * @param string $name Element name
     * @return DOMElement[]
     */
    private function childElements(DOMElement $parent, string $name): array
    {
        $elements = [];

        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $name) {
                $elements[] = $child;
            }
        }

        return $elements;
    }

    /**
     * Collapse the layout of a document out of a piece of prose
     *
     * @param string $text Text as written
     * @return string
     */
    private function normalise(string $text): string
    {
        return trim((string)preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Read a document, failing the suite rather than returning something half-read
     *
     * @param string $path Absolute path of the document
     * @return DOMDocument
     */
    private function readXml(string $path): DOMDocument
    {
        $document = new DOMDocument();

        $this->assertTrue($document->load($path), sprintf('%s could not be read.', $path));

        return $document;
    }

    /**
     * Every file the module's PHP is written in
     *
     * @return SplFileInfo[]
     */
    private function phpFiles(): array
    {
        return array_merge(
            $this->sourceFiles(self::PHP_DIRECTORIES, 'php'),
            $this->sourceFiles([self::PHTML_DIRECTORY], 'phtml')
        );
    }

    /**
     * Every file of the given extension under the given directories
     *
     * @param string[] $directories Directories relative to the module root
     * @param string $extension Extension to look for
     * @return SplFileInfo[]
     */
    private function sourceFiles(array $directories, string $extension): array
    {
        $files = [];

        foreach ($directories as $directory) {
            $path = $this->modulePath($directory);

            if (!is_dir($path)) {
                continue;
            }

            $walker = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            /** @var SplFileInfo $file */
            foreach ($walker as $file) {
                if ($file->isFile() && $file->getExtension() === $extension) {
                    $files[] = $file;
                }
            }
        }

        usort($files, static fn (SplFileInfo $a, SplFileInfo $b): int => strcmp($a->getPathname(), $b->getPathname()));

        return $files;
    }

    /**
     * Absolute path of something in the module
     *
     * @param string $relative Path relative to the module root
     * @return string
     */
    private function modulePath(string $relative): string
    {
        return dirname(__DIR__, 2) . '/' . $relative;
    }

    /**
     * The distinct members of a list, in the order they were first seen
     *
     * @param string[] $values Values swept
     * @return string[]
     */
    private function unique(array $values): array
    {
        return array_values(array_unique($values));
    }
}
