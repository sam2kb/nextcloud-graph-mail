<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Tests\Unit;

use OCA\GraphMail\Transport\Rfc5322AddressParser;
use PHPUnit\Framework\TestCase;

final class Rfc5322AddressParserTest extends TestCase
{
    private Rfc5322AddressParser $parser;

    protected function setUp(): void
    {
        $this->parser = new Rfc5322AddressParser();
    }

    public function testDisplayNameWithCommaAndFolding(): void
    {
        $result = $this->parser->parseList('"Doe, Jane" <jane@example.invalid>, bob@example.invalid');
        self::assertSame(['jane@example.invalid', 'bob@example.invalid'], $result['addresses']);
        self::assertSame([], $result['invalid']);
    }

    public function testGroupMembersAreExtracted(): void
    {
        $result = $this->parser->parseList('Team: jane@example.invalid, "Joe" <joe@example.invalid>;');
        self::assertSame(['jane@example.invalid', 'joe@example.invalid'], $result['addresses']);
    }

    public function testCommentsAreIgnored(): void
    {
        $result = $this->parser->parseList('jane@example.invalid (Jane Doe)');
        self::assertSame(['jane@example.invalid'], $result['addresses']);
    }

    public function testQuotedLocalPartIsPreserved(): void
    {
        $result = $this->parser->parseList('"john doe"@example.invalid');
        self::assertSame(['"john doe"@example.invalid'], $result['addresses']);
        self::assertTrue($this->parser->isValidAddress('"john doe"@example.invalid'));
    }

    public function testHeaderInjectionIsRejected(): void
    {
        $result = $this->parser->parseList("jane@example.invalid\r\nBcc: evil@example.invalid");
        self::assertSame([], $result['addresses']);
        self::assertNotSame([], $result['invalid']);
    }

    public function testNormalizationLowerCasesOnlyTheDomain(): void
    {
        self::assertSame('Mixed.Case@example.invalid', $this->parser->normalize('Mixed.Case@EXAMPLE.invalid'));
    }

    public function testInvalidAddressesAreReportedNotGuessed(): void
    {
        $result = $this->parser->parseList('not-an-address, jane@example.invalid');
        self::assertSame(['jane@example.invalid'], $result['addresses']);
        self::assertCount(1, $result['invalid']);
    }
    public function testAngleAddressInsideQuotedDisplayNameIsNotARecipient(): void
    {
        $parsed = $this->parser->parseList('"Example <other@example.invalid>" <actual@example.invalid>');
        self::assertSame(['actual@example.invalid'], $parsed['addresses']);
        self::assertSame([], $parsed['invalid']);
    }

    public function testMalformedListsAreRejectedRatherThanGuessed(): void
    {
        foreach (['"unfinished <a@example.invalid>', 'Team: a@example.invalid', 'name a@example.invalid', 'a@example.invalid (unfinished'] as $value) {
            self::assertNotSame([], $this->parser->parseList($value)['invalid'], $value);
        }
    }

}
