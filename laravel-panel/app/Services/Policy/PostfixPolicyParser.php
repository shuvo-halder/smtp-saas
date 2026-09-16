<?php

namespace App\Services\Policy;

class PostfixPolicyParser
{
    public const MAX_BUFFER_SIZE = 65536; // 64 KB

    private string $buffer = '';

    /**
     * Appends incoming data chunk to internal buffer and extracts any complete requests.
     *
     * @param string $chunk
     * @return PolicyRequest[]
     * @throws \OverflowException If buffer size exceeds MAX_BUFFER_SIZE without request termination.
     */
    public function feed(string $chunk): array
    {
        $this->buffer .= $chunk;

        if (strlen($this->buffer) > self::MAX_BUFFER_SIZE) {
            $this->buffer = '';
            throw new \OverflowException('Policy request exceeded maximum allowed buffer size (' . self::MAX_BUFFER_SIZE . ' bytes)');
        }

        $requests = [];

        while (true) {
            // Check for empty line ending a request: \r\n\r\n or \n\n
            $pos = false;
            $delimiterLength = 0;

            $posRN = strpos($this->buffer, "\r\n\r\n");
            $posN = strpos($this->buffer, "\n\n");

            if ($posRN !== false && ($posN === false || $posRN <= $posN)) {
                $pos = $posRN;
                $delimiterLength = 4;
            } elseif ($posN !== false) {
                $pos = $posN;
                $delimiterLength = 2;
            }

            if ($pos === false) {
                break;
            }

            $rawRequest = substr($this->buffer, 0, $pos);
            $this->buffer = substr($this->buffer, $pos + $delimiterLength);

            $attributes = $this->parseLines($rawRequest);
            if (!empty($attributes)) {
                $requests[] = PolicyRequest::fromAttributes($attributes);
            }
        }

        return $requests;
    }

    /**
     * Parses a complete raw request string into key-value attribute pairs.
     */
    public function parseLines(string $raw): array
    {
        $lines = preg_split('/\r?\n/', $raw);
        $attributes = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $eqPos = strpos($line, '=');
            if ($eqPos !== false) {
                $key = substr($line, 0, $eqPos);
                $val = substr($line, $eqPos + 1);
                $attributes[$key] = $val;
            }
        }

        return $attributes;
    }

    /**
     * Returns any remaining unparsed buffer.
     */
    public function getRemainingBuffer(): string
    {
        return $this->buffer;
    }

    /**
     * Clears internal buffer.
     */
    public function clear(): void
    {
        $this->buffer = '';
    }
}
