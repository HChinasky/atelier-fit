<?php

namespace App\Services\Feed;

use DOMCdataSection;
use DOMElement;
use DOMText;
use SimpleXMLElement;

/**
 * Lossless structural snapshot of one feed item, the shape every XML parser
 * stores in products.raw. Kept apart from the parsers so a Google Merchant
 * <item> and an Amazon <Item> land in the column in the same shape, and so a
 * field no parser maps today stays recoverable from the row tomorrow.
 *
 * A leaf with no attributes becomes its trimmed text (single-valued fields such
 * as raw['title'] stay plain strings); anything richer becomes a map. Children
 * are keyed by LOCAL name (namespace prefixes dropped, matching the parsers'
 * tolerant convention) and a name seen more than once is kept as an ordered
 * list, so repeated tags are never lost. Attributes are kept under "@name"
 * (prefix included) and free text alongside children under "#text".
 */
final class XmlSnapshot
{
    /**
     * @return array<string, mixed>|string
     */
    public static function ofSimpleXml(SimpleXMLElement $element): array|string
    {
        return self::ofElement(dom_import_simplexml($element));
    }

    /**
     * @return array<string, mixed>|string
     */
    public static function ofElement(DOMElement $element): array|string
    {
        $attributes = [];
        foreach ($element->attributes ?? [] as $attribute) {
            $attributes['@'.$attribute->nodeName] = $attribute->nodeValue;
        }

        $children = [];
        $text = '';

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $children[$child->localName][] = self::ofElement($child);
            } elseif ($child instanceof DOMText || $child instanceof DOMCdataSection) {
                $text .= $child->nodeValue;
            }
        }

        $text = trim($text);

        // Plain leaf: no attributes, no child elements — keep the bare value.
        if ($attributes === [] && $children === []) {
            return $text;
        }

        $result = $attributes;

        foreach ($children as $name => $values) {
            $result[$name] = count($values) === 1 ? $values[0] : $values;
        }

        if ($text !== '') {
            $result['#text'] = $text;
        }

        return $result;
    }
}
