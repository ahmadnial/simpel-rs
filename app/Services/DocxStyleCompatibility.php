<?php

namespace App\Services;

use App\Exceptions\DocumentConversionException;
use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

/** Make inherited table font sizes/spacing explicit for Office converters. */
class DocxStyleCompatibility
{
    private const PROPERTIES = ['pPr' => ['spacing'], 'rPr' => ['sz', 'szCs']];

    public function apply(ZipArchive $zip): void
    {
        $xml = $zip->getFromName('word/styles.xml');
        if ($xml === false) {
            return;
        }
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $dom->loadXML($xml, LIBXML_NONET) || $dom->doctype !== null) {
                throw new DocumentConversionException('Struktur style dokumen tidak valid. Buka dan simpan ulang melalui Word.', statusCode: 422);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $namespace = $dom->documentElement->namespaceURI;
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', $namespace);
        $styles = [];
        foreach ($xpath->query('/w:styles/w:style[@w:type="table"]') as $style) {
            $styles[$style->getAttributeNS($namespace, 'styleId')] = $style;
        }
        $resolved = [];
        foreach ($styles as $id => $style) {
            $this->resolve($id, $styles, $resolved, [], $xpath, $namespace);
        }
        $changed = false;
        foreach ($styles as $id => $style) {
            foreach ($resolved[$id] as $region => $groups) {
                if (! $groups) {
                    continue;
                }
                $container = $style;
                if ($region !== 'default') {
                    $container = null;
                    foreach ($xpath->query('w:tblStylePr', $style) as $conditional) {
                        if ($conditional->getAttributeNS($namespace, 'type') === $region) {
                            $container = $conditional;
                            break;
                        }
                    }
                    if ($container === null) {
                        $container = $style->appendChild($dom->createElementNS($namespace, 'w:tblStylePr'));
                        $container->setAttributeNS($namespace, 'w:type', $region);
                        $changed = true;
                    }
                }
                foreach ($groups as $group => $properties) {
                    foreach ($properties as $name => $attributes) {
                        if (! $attributes) {
                            continue;
                        }
                        $parent = $xpath->query('w:'.$group, $container)->item(0);
                        if ($parent === null) {
                            $parent = $dom->createElementNS($namespace, 'w:'.$group);
                            $before = $xpath->query($group === 'pPr' ? 'w:rPr|w:tblPr|w:trPr|w:tcPr|w:tblStylePr' : 'w:tblPr|w:trPr|w:tcPr|w:tblStylePr', $container)->item(0);
                            $container->insertBefore($parent, $before);
                        }
                        $property = $xpath->query('w:'.$name, $parent)->item(0);
                        if ($property === null) {
                            $property = $parent->appendChild($dom->createElementNS($namespace, 'w:'.$name));
                        }
                        foreach ($attributes as $attribute => $value) {
                            if (! $property->hasAttributeNS($namespace, $attribute)) {
                                $property->setAttributeNS($namespace, 'w:'.$attribute, $value);
                                $changed = true;
                            }
                        }
                    }
                }
            }
        }
        if ($changed) {
            $zip->addFromString('word/styles.xml', $dom->saveXML());
        }
    }

    private function resolve(string $id, array $styles, array &$resolved, array $visiting, DOMXPath $xpath, string $namespace): array
    {
        if (isset($resolved[$id])) {
            return $resolved[$id];
        }
        if (! isset($styles[$id])) {
            return [];
        }
        if (isset($visiting[$id])) {
            throw new DocumentConversionException('Pewarisan style tabel membentuk siklus. Perbaiki style melalui Word lalu unggah ulang.', statusCode: 422);
        }
        $visiting[$id] = true;
        $style = $styles[$id];
        $base = $xpath->query('w:basedOn', $style)->item(0);
        $values = $base ? $this->resolve($base->getAttributeNS($namespace, 'val'), $styles, $resolved, $visiting, $xpath, $namespace) : [];
        $values['default'] = $this->properties($style, $values['default'] ?? [], $xpath, $namespace);
        foreach ($xpath->query('w:tblStylePr', $style) as $conditional) {
            $region = $conditional->getAttributeNS($namespace, 'type');
            $values[$region] = $this->properties($conditional, $values[$region] ?? [], $xpath, $namespace);
        }

        return $resolved[$id] = $values;
    }

    private function properties(DOMElement $container, array $values, DOMXPath $xpath, string $namespace): array
    {
        foreach (self::PROPERTIES as $group => $names) {
            foreach ($names as $name) {
                $property = $xpath->query('w:'.$group.'/w:'.$name, $container)->item(0);
                if ($property === null) {
                    continue;
                }
                $attributes = [];
                foreach ($property->attributes as $attribute) {
                    if ($attribute->namespaceURI === $namespace) {
                        $attributes[$attribute->localName] = $attribute->value;
                    }
                }
                $values[$group][$name] = $name === 'spacing'
                    ? array_replace($values[$group][$name] ?? [], $attributes)
                    : $attributes;
            }
        }

        return $values;
    }
}
