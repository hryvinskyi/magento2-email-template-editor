<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Config;

use Magento\Framework\Config\Dom as ConfigDom;

/**
 * The configuration merge, able to match a variable whose expression holds quotes or brackets
 *
 * The merge finds the node a contribution adds to with an XPath built from the node's identifying
 * attributes. The framework writes each value between single quotes without escaping it, and later
 * removes those predicates again with a pattern that ends one at the first closing bracket. An
 * expression is template code - `this.getUrl($store,'customer/account/',[_nosid:1])` - so either
 * one breaks it: the predicate is not valid XPath, or what is left of it hides the path the
 * merge looks up a child's identifying attribute by. Nothing could be contributed to such an entry;
 * a contribution became a second entry for the same directive and the schema refused the file.
 *
 * Here each value is written as an XPath literal that holds any character, and a child's path is
 * matched with every predicate removed by reading the literals rather than guessing at them.
 */
class Dom extends ConfigDom
{
    /**
     * @inheritDoc
     *
     * @param \DOMElement $node
     * @param string $parentPath
     * @return string
     */
    protected function _getNodePathByParent(\DOMElement $node, $parentPath)
    {
        $prefix = $this->rootNamespace === null ? '' : self::ROOT_NAMESPACE_PREFIX . ':';
        $path = $parentPath . '/' . $prefix . $node->tagName;
        // The framework documents a string; a node identified by several attributes gets their map.
        /** @var string|array<string, string>|null $idAttribute */
        $idAttribute = $this->nodeMergingConfig->getIdAttribute($this->withoutPredicates($path));

        if (is_array($idAttribute)) {
            $constraints = [];

            foreach ($idAttribute as $attribute) {
                $constraints[] = '@' . $attribute . '=' . $this->literal($node->getAttribute($attribute));
            }

            return $path . '[' . implode(' and ', $constraints) . ']';
        }

        if (is_string($idAttribute) && $idAttribute !== '' && $node->getAttribute($idAttribute) !== '') {
            return $path . '[@' . $idAttribute . '=' . $this->literal($node->getAttribute($idAttribute)) . ']';
        }

        return $path;
    }

    /**
     * An XPath 1.0 string literal for any value
     *
     * XPath has no escape inside a literal, so a value holding both kinds of quote is spelled as a
     * concat() of pieces that each avoid one of them.
     *
     * @param string $value
     * @return string
     */
    private function literal(string $value): string
    {
        if (!str_contains($value, "'")) {
            return "'" . $value . "'";
        }

        if (!str_contains($value, '"')) {
            return '"' . $value . '"';
        }

        return "concat('" . str_replace("'", "', \"'\", '", $value) . "')";
    }

    /**
     * A node path with every predicate removed, brackets and quotes inside literals included
     *
     * @param string $path
     * @return string
     */
    private function withoutPredicates(string $path): string
    {
        $result = '';
        $depth = 0;
        $quote = null;
        $length = strlen($path);

        for ($index = 0; $index < $length; $index++) {
            $char = $path[$index];

            if ($quote !== null) {
                $quote = $char === $quote ? null : $quote;
                continue;
            }

            if ($depth > 0 && ($char === "'" || $char === '"')) {
                $quote = $char;
            } elseif ($char === '[') {
                $depth++;
            } elseif ($char === ']') {
                $depth--;
            } elseif ($depth === 0) {
                $result .= $char;
            }
        }

        return $result;
    }
}
