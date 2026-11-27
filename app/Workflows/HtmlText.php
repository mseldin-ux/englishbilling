<?php

namespace App\Workflows;

use DOMDocument;
use DOMXPath;

final class HtmlText
{
    public static function convert(string $html): string
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>', LIBXML_NONET);
            $xpath = new DOMXPath($document);
            foreach (iterator_to_array($xpath->query('//script|//style|//head')) as $element) {
                $element->parentNode->removeChild($element);
            }
            foreach ($xpath->query('//a[@href]') as $link) {
                $url = trim($link->getAttribute('href'));
                if (preg_match('~^(https?://|mailto:)~i', $url) && $url !== trim($link->textContent)) {
                    $link->appendChild($document->createTextNode(' ('.$url.')'));
                }
            }
            foreach (iterator_to_array($xpath->query('//br|//p|//div|//li|//tr|//h1|//h2|//h3|//h4|//h5|//h6')) as $element) {
                $element->parentNode->insertBefore($document->createTextNode("\n"), $element);
                if ($element->nodeName !== 'br') {
                    $element->appendChild($document->createTextNode("\n"));
                }
            }

            return trim(preg_replace("/\n{3,}/", "\n\n", $document->getElementsByTagName('body')->item(0)->textContent));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
