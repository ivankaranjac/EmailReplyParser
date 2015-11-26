<?php

/**
 * This file is part of the EmailReplyParser package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

namespace EmailReplyParser\Parser;

use EmailReplyParser\Email;
use EmailReplyParser\Fragment;

/**
 * @author William Durand <william.durand1@gmail.com>
 */
class EmailParser
{
    const SIG_REGEX   = '/(?:--\s*$|__\s*$|\w-$)|(?:^(?:\w+\s*){1,3} ym morf tneS$)/s';

    const QUOTE_REGEX = '/>+$/s';

    /**
     * @var string[]
     */
    private $quoteHeadersRegex = array(
        '/^(On\s.+?wrote:)$/ms', // On DATE, NAME <EMAIL> wrote:
        '/^(Le\s.+?écrit :)$/ms', // Le DATE, NAME <EMAIL> a écrit :
        '/^(El\s.+?escribió:)$/ms', // El DATE, NAME <EMAIL> escribió:
        '/^(W dniu\s.+?(pisze|napisał):)$/ms', // W dniu DATE, NAME <EMAIL> pisze|napisał:
        '/^(Den\s.+\sskrev\s.+:)$/m', // Den DATE skrev NAME <EMAIL>:
        '/^(Am\s.+\sum\s.+\sschrieb\s.+:)$/m', // Am DATE um TIME schrieb NAME:
        '/^(.+\s<.+>\sschrieb:)$/m', // NAME <EMAIL> schrieb:
        '/^(20[0-9]{2}\-(?:0?[1-9]|1[012])\-(?:0?[0-9]|[1-2][0-9]|3[01]|[1-9])\s[0-2]?[0-9]:\d{2}\s.+?:)$/ms', // 20YY-MM-DD HH:II GMT+01:00 NAME <EMAIL>:
    );

    private $quoteRegex = array(
        '/((On\h(?:[^\n\r]+)\n?(?:[^\n\r]*?)wrote\h?:)(.*))/si', // On DATE, NAME <EMAIL> wrote:
        '/((Le\h(?:[^\n\r]+)\n?(?:[^\n\r]*?)écrit\h?:)(.*))/si', // Le DATE, NAME <EMAIL> a écrit :
        '/((El\h(?:[^\n\r]+)\n?(?:[^\n\r]*?)scribió\h?:)(.*))/si', // El DATE, NAME <EMAIL> escribió:
        '/((20[0-9]{2}\-(?:0?[1-9]|1[012])\-(?:0?[0-9]|[1-2][0-9]|3[01]|[1-9])\s[0-2]?[0-9]:\d{2}\s.+?:\n)(.*))/si' // 20YY-MM-DD HH:II GMT+01:00 NAME <EMAIL>:
    );

    private $quoteRegex2 = array(
        "en" => array("From", "Sent", "To", "Subject", "Date"),
        "fr" => array("De", "Envoyé", "À", "Objet", "Date"),
        "es" => array("De", "Enviado", "A", "Sujeto", "Fecha")
    );

    /**
     * @var FragmentDTO[]
     */
    private $fragments = array();

    /**
     * @param $html
     * @param string|null $tag
     * @return string
     */
    public function parseHtml($html, $tag = null)
    {
        if ($tag && false !== strpos($html, $tag)) {
            $html = substr($html, 0, strpos($html, $tag));
        }

        $html = preg_replace('/(<(script|style)\b[^>]*>).*?(<\/\2>)/is', "", $html);

        $html = preg_replace('/\<br.*?>/si', "\n", $html);
        $html = preg_replace('/\<\/div>/si', "\n", $html);

        $text = strip_tags($html);


        foreach ($this->quoteRegex as $reg) {
            $text = preg_replace($reg, "", $text);
        }

        foreach ($this->quoteRegex2 as $tag) {
            $text = preg_replace(
                '/((^\s*' . $tag[0] . '\s?:\s?\w.+\n)|(^\s*' . $tag[1] . '\s?:\s?\w.+\n)|(^\s*' . $tag[2] . '\s?:\s?\w.+\n)|(^\s*' . $tag[3] . '\s?:\s?\w.+\n)|(^\s*' . $tag[4] . '\s?:\s?\w.+\n))' .
                '?((^\s*' . $tag[0] . '\s?:\s?\w.+\n)|(^\s*' . $tag[1] . '\s?:\s?\w.+\n)|(^\s*' . $tag[2] . '\s?:\s?\w.+\n)|(^\s*' . $tag[3] . '\s?:\s?\w.+\n)|(^\s*' . $tag[4] . '\s?:\s?\w.+\n))' .
                '(.*\n)+/mi',
                "", $text
            );
        }

        $text = preg_replace('/\h+/um', " ", $text);

        $text = preg_replace('/(\h*?\v)+/s', "\n", $text);

        $text = html_entity_decode($text);
        $text = htmlspecialchars_decode($text, ENT_QUOTES);

        return trim($text);
    }

    /**
     * Parse a text which represents an email and splits it into fragments.
     *
     * @param string $text A text.
     *
     * @return Email
     */
    public function parse($text)
    {
        $text = str_replace("\r\n", "\n", $text);

        foreach ($this->quoteHeadersRegex as $regex) {
            if (preg_match($regex, $text, $matches)) {
                $text = str_replace($matches[1], str_replace("\n", ' ', $matches[1]), $text);
            }
        }

        $fragment = null;
        foreach (explode("\n", strrev($text)) as $line) {
            $line = rtrim($line, "\n");

            if (!$this->isSignature($line)) {
                $line = ltrim($line);
            }

            if ($fragment) {
                $last = end($fragment->lines);

                if ($this->isSignature($last)) {
                    $fragment->isSignature = true;
                    $this->addFragment($fragment);

                    $fragment = null;
                } elseif (empty($line) && $this->isQuoteHeader($last)) {
                    $fragment->isQuoted = true;
                    $this->addFragment($fragment);

                    $fragment = null;
                }
            }

            $isQuoted = $this->isQuote($line);

            if (null === $fragment || !$this->isFragmentLine($fragment, $line, $isQuoted)) {
                if ($fragment) {
                    $this->addFragment($fragment);
                }

                $fragment = new FragmentDTO();
                $fragment->isQuoted = $isQuoted;
            }

            $fragment->lines[] = $line;
        }

        if ($fragment) {
            $this->addFragment($fragment);
        }

        $email = $this->createEmail($this->fragments);

        $this->fragments = array();

        return $email;
    }

    /**
     * @return string[]
     */
    public function getQuoteHeadersRegex()
    {
        return $this->quoteHeadersRegex;
    }

    /**
     * @param string[] $quoteHeadersRegex
     *
     * @return EmailParser
     */
    public function setQuoteHeadersRegex(array $quoteHeadersRegex)
    {
        $this->quoteHeadersRegex = $quoteHeadersRegex;

        return $this;
    }

    /**
     * @param FragmentDTO[] $fragmentDTOs
     *
     * @return Email
     */
    protected function createEmail(array $fragmentDTOs)
    {
        $fragments = array();
        foreach (array_reverse($fragmentDTOs) as $fragment) {
            $fragments[] = new Fragment(
                preg_replace("/^\n/", '', strrev(implode("\n", $fragment->lines))),
                $fragment->isHidden,
                $fragment->isSignature,
                $fragment->isQuoted
            );
        }

        return new Email($fragments);
    }

    private function isQuoteHeader($line)
    {
        foreach ($this->quoteHeadersRegex as $regex) {
            if (preg_match($regex, strrev($line))) {
                return true;
            }
        }

        return false;
    }

    private function isSignature($line)
    {
        return preg_match(static::SIG_REGEX, $line) ? true : false;
    }

    /**
     * @param string $line
     */
    private function isQuote($line)
    {
        return preg_match(static::QUOTE_REGEX, $line) ? true : false;
    }

    private function isEmpty(FragmentDTO $fragment)
    {
        return '' === implode('', $fragment->lines);
    }

    /**
     * @param string  $line
     * @param boolean $isQuoted
     */
    private function isFragmentLine(FragmentDTO $fragment, $line, $isQuoted)
    {
        return $fragment->isQuoted === $isQuoted ||
            ($fragment->isQuoted && ($this->isQuoteHeader($line) || empty($line)));
    }

    private function addFragment(FragmentDTO $fragment)
    {
        if ($fragment->isQuoted || $fragment->isSignature || $this->isEmpty($fragment)) {
            $fragment->isHidden = true;
        }

        $this->fragments[] = $fragment;
    }
}
