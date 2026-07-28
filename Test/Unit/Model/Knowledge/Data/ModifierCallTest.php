<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Knowledge\Data;

use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\ModifierCall;
use PHPUnit\Framework\TestCase;

class ModifierCallTest extends TestCase
{
    public function testACallWithoutArgumentsIsJustItsName(): void
    {
        self::assertSame('nl2br', (new ModifierCall('nl2br'))->toString());
        self::assertSame([], (new ModifierCall('nl2br'))->getArguments());
    }

    /**
     * The renderer splits a modifier call on colons and passes everything after the name through as
     * positional arguments, so that is the form a call has to serialise back to.
     *
     * @return void
     */
    public function testArgumentsAreJoinedOntoTheNameWithColons(): void
    {
        self::assertSame('escape:html', (new ModifierCall('escape', ['html']))->toString());
        self::assertSame('date:Y-m-d:UTC', (new ModifierCall('date', ['Y-m-d', 'UTC']))->toString());
    }

    /**
     * A name the renderer does not know is silently ignored rather than reported, so the spelling in
     * the template is the only evidence of what happens and must be preserved exactly.
     *
     * @return void
     */
    public function testTheNameIsKeptExactlyAsWritten(): void
    {
        $call = new ModifierCall('ESCAPE', ['HTML']);

        self::assertSame('ESCAPE', $call->getName());
        self::assertSame(['HTML'], $call->getArguments());
        self::assertSame('ESCAPE:HTML', $call->toString());
    }

    public function testAnEmptyArgumentStillOccupiesItsPosition(): void
    {
        self::assertSame('escape::url', (new ModifierCall('escape', ['', 'url']))->toString());
    }
}
