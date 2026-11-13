<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Tests\Support;

use OCA\GraphMail\Transport\InboundMessage;
use OCA\GraphMail\Transport\SubmissionHandler;
use OCA\GraphMail\Transport\SubmissionResult;

final class FakeSubmissionHandler implements SubmissionHandler
{
    /** @var list<InboundMessage> */
    public array $messages = [];
    public SubmissionResult $result;

    public function __construct(?SubmissionResult $result = null)
    {
        $this->result = $result ?? SubmissionResult::accepted('corr123');
    }

    public function handle(InboundMessage $message): SubmissionResult
    {
        $this->messages[] = $message;
        return $this->result;
    }
}
