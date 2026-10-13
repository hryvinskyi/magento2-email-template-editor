<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Knowledge\Config;

use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Config\Dom;
use Magento\Framework\Config\ValidationStateInterface;
use PHPUnit\Framework\TestCase;

/**
 * A contribution is merged into the entry it names, whatever its expression holds.
 */
class DomTest extends TestCase
{
    /**
     * The identifying attributes the knowledge reader merges by
     */
    private const ID_ATTRIBUTES = [
        '/variables/variable' => ['kind' => 'kind', 'expression' => 'expression'],
        '/variables/variable/caveat' => 'id',
    ];

    /**
     * @return array<string, array{0: string}>
     */
    public static function expressionProvider(): array
    {
        return [
            'single quotes and brackets' => ["this.getUrl(\$store,'customer/account/',[_nosid:1])"],
            'double quotes' => ['order.getData("increment_id")'],
            'both quotes' => ["a('x').b(\"y\")[0]"],
            'plain' => ['order.increment_id'],
        ];
    }

    /**
     * A caveat contributed to an entry lands beside the shipped one, in the same entry
     *
     * @dataProvider expressionProvider
     * @param string $expression
     * @return void
     */
    public function testAContributedCaveatJoinsTheEntryItNames(string $expression): void
    {
        $attribute = htmlspecialchars($expression, ENT_QUOTES | ENT_XML1);
        $dom = new Dom(
            '<variables><variable kind="var" expression="' . $attribute . '">'
            . '<caveat id="shipped">Shipped</caveat></variable></variables>',
            $this->createMock(ValidationStateInterface::class),
            self::ID_ATTRIBUTES
        );

        $dom->merge(
            '<variables><variable kind="var" expression="' . $attribute . '">'
            . '<caveat id="added">Added</caveat><caveat id="shipped">Reworded</caveat></variable></variables>'
        );

        $variables = $dom->getDom()->getElementsByTagName('variable');
        self::assertSame(1, $variables->length, 'The contribution became a second entry.');

        $caveats = [];
        foreach ($dom->getDom()->getElementsByTagName('caveat') as $caveat) {
            $caveats[$caveat->getAttribute('id')] = $caveat->textContent;
        }

        self::assertSame(['shipped' => 'Reworded', 'added' => 'Added'], $caveats);
    }
}
