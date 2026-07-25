<?php
declare(strict_types=1);

namespace Compta\Modules\Tresorerie\Parsing;

use Compta\Modules\Tresorerie\TreasuryException;

/**
 * Petit lecteur XML à surface volontairement limitée.
 *
 * L'environnement cible n'impose ni DOM, ni XMLReader. Ce lecteur n'interprète
 * jamais de DTD ou d'entité externe et borne taille, profondeur et nombre de
 * nœuds. Il suffit aux messages ISO 20022 structurés.
 */
final class SecureXmlParser
{
    public function parse(string $xml): SecureXmlNode
    {
        if (strlen($xml) > 10 * 1024 * 1024) {
            throw new TreasuryException('Fichier XML trop volumineux.');
        }
        if (preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xml) === 1) {
            throw new TreasuryException('DTD et entités XML interdites.');
        }
        if (preg_match('/<\?(?!xml\b)/i', $xml) === 1) {
            throw new TreasuryException('Instruction de traitement XML interdite.');
        }
        preg_match_all(
            '/<!--.*?-->|<!\[CDATA\[.*?\]\]>|<\?.*?\?>|<[^>]+>|[^<]+/s',
            $xml,
            $matches
        );
        $stack = [];
        $root = null;
        $nodes = 0;
        foreach ($matches[0] as $token) {
            if (str_starts_with($token, '<?xml') || str_starts_with($token, '<!--')) {
                continue;
            }
            if (str_starts_with($token, '<![CDATA[')) {
                if ($stack !== []) {
                    $stack[array_key_last($stack)]->text .= substr($token, 9, -3);
                }
                continue;
            }
            if ($token[0] !== '<') {
                if ($stack !== []) {
                    $stack[array_key_last($stack)]->text .= $this->decode($token);
                }
                continue;
            }
            if (str_starts_with($token, '</')) {
                $name = trim(substr($token, 2, -1));
                $node = array_pop($stack);
                if (!$node instanceof SecureXmlNode || $node->name !== $name) {
                    throw new TreasuryException('XML mal formé : balises incohérentes.');
                }
                continue;
            }
            $selfClosing = str_ends_with(rtrim($token), '/>');
            $inside = trim(substr($token, 1, $selfClosing ? -2 : -1));
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_.:-]*)(.*)$/s', $inside, $parts) !== 1) {
                throw new TreasuryException('XML mal formé : balise invalide.');
            }
            $node = new SecureXmlNode($parts[1], $this->attributes($parts[2]));
            $nodes++;
            if ($nodes > 100000) {
                throw new TreasuryException('XML refusé : trop de nœuds.');
            }
            if ($stack === []) {
                if ($root !== null) {
                    throw new TreasuryException('XML mal formé : plusieurs racines.');
                }
                $root = $node;
            } else {
                $stack[array_key_last($stack)]->children[] = $node;
            }
            if (!$selfClosing) {
                $stack[] = $node;
                if (count($stack) > 80) {
                    throw new TreasuryException('XML refusé : profondeur excessive.');
                }
            }
        }
        if (!$root instanceof SecureXmlNode || $stack !== []) {
            throw new TreasuryException('Document XML incomplet ou vide.');
        }
        return $root;
    }

    /** @return array<string,string> */
    private function attributes(string $source): array
    {
        $attributes = [];
        $offset = 0;
        $length = strlen($source);
        while ($offset < $length) {
            if (preg_match(
                '/\G\s+([A-Za-z_][A-Za-z0-9_.:-]*)\s*=\s*("([^"]*)"|\'([^\']*)\')/A',
                $source,
                $match,
                0,
                $offset
            ) !== 1) {
                if (trim(substr($source, $offset)) !== '') {
                    throw new TreasuryException('XML mal formé : attribut invalide.');
                }
                break;
            }
            $attributes[$match[1]] = $this->decode($match[3] !== '' ? $match[3] : $match[4]);
            $offset += strlen($match[0]);
        }
        return $attributes;
    }

    private function decode(string $value): string
    {
        if (preg_match('/&(?!(?:amp|lt|gt|quot|apos|#\d+|#x[0-9A-Fa-f]+);)/', $value) === 1) {
            throw new TreasuryException('Entité XML non autorisée.');
        }
        return html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
