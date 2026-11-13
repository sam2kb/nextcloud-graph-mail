<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Transport;

/**
 * Small RFC 5322 address-list reader for the profile produced by the native
 * Nextcloud mailer. It is not a general MIME parser: display names, comments,
 * quoted strings, groups and folding are handled, and unsupported input is
 * rejected instead of guessed.
 */
final class Rfc5322AddressParser
{
    /**
     * @return array{addresses: list<string>, invalid: list<string>}
     */
    public function parseList(string $value): array
    {
        $addresses = [];
        $invalid = [];

        if (str_contains($value, "\r") || str_contains($value, "\n")) {
            return ['addresses' => [], 'invalid' => [$value]];
        }

        $items = $this->splitItems($value);
        if ($items === null) {
            return ['addresses' => [], 'invalid' => [$value]];
        }
        foreach ($items as $item) {
            $item = trim($item);
            if ($item === '' || $this->isGroupMarker($item)) {
                continue;
            }
            $address = $this->extractAddress($item);
            if ($address === null) {
                $invalid[] = $item;
                continue;
            }
            if (!$this->isValidAddress($address)) {
                $invalid[] = $item;
                continue;
            }
            $addresses[] = $address;
        }

        return ['addresses' => $addresses, 'invalid' => $invalid];
    }

    public function isValidAddress(string $address): bool
    {
        if ($address === '' || strlen($address) > 320) {
            return false;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $address)) {
            return false;
        }
        $local = '"(?:[^"\\\\\r\n]|\\\\.)*"|[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~-]+(?:\.[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~-]+)*';
        $label = '[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?';
        $domain = '(?:' . $label . '(?:\.' . $label . ')*|\[[0-9A-Fa-f:.]+\])';
        return preg_match('/^(?:' . $local . ')@' . $domain . '$/D', $address) === 1;
    }

    /**
     * Normalize only what SMTP treats case-insensitively (the domain).
     * Local parts are compared exactly; no dot-stripping or folding.
     */
    public function normalize(string $address): string
    {
        $at = strrpos($address, '@');
        if ($at === false) {
            return $address;
        }
        return substr($address, 0, $at) . '@' . strtolower(substr($address, $at + 1));
    }

    /**
     * Split on commas and semicolons outside quoted strings, angle brackets
     * and comments.
     *
     * @return list<string>
     */
    private function splitItems(string $value): ?array
    {
        $items = [];
        $current = '';
        $inQuotes = false;
        $commentDepth = 0;
        $inAngle = false;
        $inGroup = false;
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($inQuotes) {
                if ($char === '\\' && $i + 1 < $length) {
                    $current .= $char . $value[++$i];
                    continue;
                }
                if ($char === '"') {
                    $inQuotes = false;
                }
                $current .= $char;
                continue;
            }
            if ($commentDepth > 0) {
                if ($char === '\\' && $i + 1 < $length) {
                    $current .= $char . $value[++$i];
                    continue;
                }
                if ($char === '(') {
                    $commentDepth++;
                } elseif ($char === ')') {
                    $commentDepth--;
                }
                $current .= $char;
                continue;
            }
            if ($char === '"') {
                $inQuotes = true;
                $current .= $char;
                continue;
            }
            if ($char === '(') {
                $commentDepth = 1;
                $current .= $char;
                continue;
            }
            if ($char === '<') {
                if ($inAngle) {
                    return null;
                }
                $inAngle = true;
                $current .= $char;
                continue;
            }
            if ($char === '>') {
                if (!$inAngle) {
                    return null;
                }
                $inAngle = false;
                $current .= $char;
                continue;
            }
            if (!$inAngle && $char === ':' && !str_contains($current, '@')) {
                if ($inGroup || trim($current) === '') {
                    return null;
                }
                $inGroup = true;
                $current = '';
                continue;
            }
            if (!$inAngle && $char === ';') {
                if (!$inGroup) {
                    return null;
                }
                $inGroup = false;
                $items[] = $current;
                $current = '';
                continue;
            }
            if (!$inAngle && $char === ',') {
                $items[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        if ($inQuotes || $commentDepth !== 0 || $inAngle || $inGroup) {
            return null;
        }
        $items[] = $current;
        return $items;
    }

    private function isGroupMarker(string $item): bool
    {
        // "Group name:" with no address, or a closing ";" artifact.
        return preg_match('/^[^@<>"]*:\s*$/', $item) === 1;
    }

    private function extractAddress(string $item): ?string
    {
        $item = trim($this->stripComments($item));
        $quoted = false;
        $start = null;
        $end = null;
        for ($i = 0, $length = strlen($item); $i < $length; $i++) {
            $char = $item[$i];
            if ($char === '\\' && $quoted) {
                $i++;
                continue;
            }
            if ($char === '"') {
                $quoted = !$quoted;
                continue;
            }
            if ($quoted) {
                continue;
            }
            if ($char === '<') {
                if ($start !== null) {
                    return null;
                }
                $start = $i;
            } elseif ($char === '>') {
                if ($start === null || $end !== null) {
                    return null;
                }
                $end = $i;
            }
        }
        if ($quoted) {
            return null;
        }
        if ($start !== null) {
            if ($end === null || trim(substr($item, $end + 1)) !== '') {
                return null;
            }
            return trim(substr($item, $start + 1, $end - $start - 1));
        }
        return $end === null && $item !== '' ? $item : null;
    }

    private function stripComments(string $value): string
    {
        $result = '';
        $depth = 0;
        $quoted = false;
        for ($i = 0, $length = strlen($value); $i < $length; $i++) {
            $char = $value[$i];
            if ($char === '\\' && ($quoted || $depth > 0) && $i + 1 < $length) {
                if ($depth === 0) {
                    $result .= $char . $value[$i + 1];
                }
                $i++;
                continue;
            }
            if ($depth > 0) {
                if ($char === '(') {
                    $depth++;
                }
                if ($char === ')') {
                    $depth--;
                }
                continue;
            }
            if ($char === '"') {
                $quoted = !$quoted;
            }
            if (!$quoted && $char === '(') {
                $depth = 1;
                continue;
            }
            if (!$quoted && $char === ')') {
                continue;
            }
            $result .= $char;
        }
        return $result;
    }
}
