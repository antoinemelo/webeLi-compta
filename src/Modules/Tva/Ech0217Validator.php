<?php
declare(strict_types=1);

namespace Compta\Modules\Tva;

use Compta\Modules\Tresorerie\Parsing\SecureXmlNode;
use Compta\Modules\Tresorerie\Parsing\SecureXmlParser;
use DateTimeImmutable;
use DOMDocument;

final class Ech0217Validator
{
    public const VERSION = '2.0.0';
    public const NAMESPACE = 'http://www.ech.ch/xmlns/eCH-0217/2';

    public function __construct(
        private readonly string $schemaPath,
    ) {
    }

    /** @return list<string> */
    public function validate(string $xml): array
    {
        if (!is_file($this->schemaPath)) {
            return ['Profil XSD eCH-0217 introuvable.'];
        }
        if (class_exists(DOMDocument::class)) {
            return $this->validateWithLibxml($xml);
        }
        return $this->validatePortable($xml);
    }

    /** @return list<string> */
    private function validateWithLibxml(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $document = new DOMDocument();
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        $valid = $loaded && $document->schemaValidate($this->schemaPath);
        $errors = [];
        if (!$valid) {
            foreach (libxml_get_errors() as $error) {
                $errors[] = trim($error->message) . ' (ligne ' . $error->line . ')';
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $errors === [] && !$valid ? ['XML non conforme au XSD eCH-0217.'] : $errors;
    }

    /**
     * Validateur portable du même profil XSD, utilisé lorsque ext-dom n'est
     * pas disponible. Il contrôle namespace, séquences, cardinalités et types.
     *
     * @return list<string>
     */
    private function validatePortable(string $xml): array
    {
        if (str_starts_with($xml, "\xEF\xBB\xBF")) {
            return ['Le BOM UTF-8 est interdit par eCH-0217.'];
        }
        try {
            $root = (new SecureXmlParser())->parse($xml);
        } catch (\Throwable $exception) {
            return [$exception->getMessage()];
        }
        $errors = [];
        if (
            $root->localName() !== 'VATDeclaration'
            || $root->attribute('xmlns:eCH-0217') !== self::NAMESPACE
        ) {
            $errors[] = 'Racine ou namespace eCH-0217 invalide.';
            return $errors;
        }
        $rootNames = array_map(
            static fn (SecureXmlNode $node): string => $node->localName(),
            $root->children
        );
        if (
            count($rootNames) < 4
            || $rootNames[0] !== 'generalInformation'
            || $rootNames[1] !== 'turnoverComputation'
            || !in_array($rootNames[2], ['effectiveReportingMethod', 'simpleTaxRateMethod'], true)
            || $rootNames[3] !== 'payableTax'
            || (isset($rootNames[4]) && $rootNames[4] !== 'otherFlowsOfFunds')
            || count($rootNames) > 5
        ) {
            $errors[] = 'Séquence racine non conforme au XSD.';
            return $errors;
        }
        $general = $root->children[0];
        $this->exactSequence($general, [
            'uid', 'organisationName', 'generationTime', 'reportingPeriodFrom',
            'reportingPeriodTill', 'typeOfSubmission', 'formOfReporting',
            'businessReferenceId', 'sendingApplication',
        ], $errors, 'generalInformation');
        $this->matches($general->child('uid'), '/^CHE[1-9][0-9]{8}$/', $errors, 'uid');
        $this->length($general->child('organisationName'), 1, 255, $errors, 'organisationName');
        $this->matches(
            $general->child('generationTime'),
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/',
            $errors,
            'generationTime'
        );
        $this->date($general->child('reportingPeriodFrom'), $errors, 'reportingPeriodFrom');
        $this->date($general->child('reportingPeriodTill'), $errors, 'reportingPeriodTill');
        $this->enumeration($general->child('typeOfSubmission'), ['1', '2', '3'], $errors);
        $this->enumeration($general->child('formOfReporting'), ['1', '2'], $errors);
        $this->length($general->child('businessReferenceId'), 1, 50, $errors, 'businessReferenceId');
        $sending = $general->child('sendingApplication');
        if ($sending !== null) {
            $this->exactSequence(
                $sending,
                ['manufacturer', 'product', 'productVersion'],
                $errors,
                'sendingApplication'
            );
            $this->length($sending->child('manufacturer'), 1, 30, $errors, 'manufacturer');
            $this->length($sending->child('product'), 1, 30, $errors, 'product');
            $this->length($sending->child('productVersion'), 1, 10, $errors, 'productVersion');
        }
        $turnover = $root->children[1];
        $this->orderedOptionalSequence($turnover, [
            'totalConsideration' => [1, 1],
            'suppliesToForeignCountries' => [0, 1],
            'suppliesAbroad' => [0, 1],
            'transferNotificationProcedure' => [0, 1],
            'suppliesExemptFromTax' => [0, 1],
            'reductionOfConsideration' => [0, 1],
            'variousDeduction' => [0, 1],
        ], $errors, 'turnoverComputation');
        foreach ($turnover->children as $child) {
            if ($child->localName() !== 'variousDeduction') {
                $this->decimal($child, false, $errors);
            }
        }
        $method = $root->children[2];
        if ($method->localName() === 'effectiveReportingMethod') {
            $this->validateEffective($method, $errors);
        } else {
            $this->validateSimple($method, $errors);
        }
        $this->decimal($root->children[3], false, $errors);
        if (isset($root->children[4])) {
            $this->orderedOptionalSequence($root->children[4], [
                'subsidies' => [0, 1], 'donations' => [0, 1],
            ], $errors, 'otherFlowsOfFunds');
            foreach ($root->children[4]->children as $child) {
                $this->decimal($child, false, $errors);
            }
        }
        return $errors;
    }

    /** @param list<string> $errors */
    private function validateEffective(SecureXmlNode $node, array &$errors): void
    {
        $this->orderedOptionalSequence($node, [
            'grossOrNet' => [1, 1], 'opted' => [0, 1],
            'suppliesPerTaxRate' => [0, 100], 'acquisitionTax' => [0, 100],
            'inputTaxMaterialAndServices' => [0, 1],
            'inputTaxInvestments' => [0, 1],
            'subsequentInputTaxDeduction' => [0, 1],
            'inputTaxCorrections' => [0, 1], 'inputTaxReductions' => [0, 1],
        ], $errors, 'effectiveReportingMethod');
        $this->enumeration($node->child('grossOrNet'), ['1', '2'], $errors);
        foreach ($node->children as $child) {
            if (in_array($child->localName(), ['suppliesPerTaxRate', 'acquisitionTax'], true)) {
                $this->taxRateTurnover($child, false, $errors);
            } elseif ($child->localName() !== 'grossOrNet') {
                $this->decimal($child, false, $errors);
            }
        }
    }

    /** @param list<string> $errors */
    private function validateSimple(SecureXmlNode $node, array &$errors): void
    {
        $this->orderedOptionalSequence($node, [
            'suppliesPerTaxRate' => [0, 100], 'acquisitionTax' => [0, 100],
            'inputTaxCorrections' => [0, 1],
        ], $errors, 'simpleTaxRateMethod');
        foreach ($node->children as $child) {
            if ($child->localName() === 'suppliesPerTaxRate') {
                $this->taxRateTurnover($child, true, $errors);
            } elseif ($child->localName() === 'acquisitionTax') {
                $this->taxRateTurnover($child, false, $errors);
            } else {
                $this->decimal($child, false, $errors);
            }
        }
    }

    /** @param list<string> $errors */
    private function taxRateTurnover(SecureXmlNode $node, bool $activity, array &$errors): void
    {
        $expected = $activity ? ['activityID', 'taxRate', 'turnover'] : ['taxRate', 'turnover'];
        $this->exactSequence($node, $expected, $errors, $node->localName());
        if ($activity) {
            $this->length($node->child('activityID'), 5, 5, $errors, 'activityID');
        }
        $this->decimal($node->child('taxRate'), true, $errors);
        $this->decimal($node->child('turnover'), false, $errors);
    }

    /** @param list<string> $expected @param list<string> $errors */
    private function exactSequence(
        SecureXmlNode $node,
        array $expected,
        array &$errors,
        string $context,
    ): void {
        $actual = array_map(
            static fn (SecureXmlNode $child): string => $child->localName(),
            $node->children
        );
        if ($actual !== $expected) {
            $errors[] = "Séquence {$context} non conforme au XSD.";
        }
    }

    /**
     * @param array<string,array{0:int,1:int}> $definition
     * @param list<string> $errors
     */
    private function orderedOptionalSequence(
        SecureXmlNode $node,
        array $definition,
        array &$errors,
        string $context,
    ): void {
        $positions = array_flip(array_keys($definition));
        $counts = [];
        $last = -1;
        foreach ($node->children as $child) {
            $name = $child->localName();
            if (!isset($positions[$name]) || $positions[$name] < $last) {
                $errors[] = "Élément ou ordre invalide dans {$context}: {$name}.";
                return;
            }
            $last = $positions[$name];
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }
        foreach ($definition as $name => [$min, $max]) {
            $count = $counts[$name] ?? 0;
            if ($count < $min || $count > $max) {
                $errors[] = "Cardinalité XSD invalide pour {$context}/{$name}.";
            }
        }
    }

    /** @param list<string> $errors */
    private function decimal(?SecureXmlNode $node, bool $percent, array &$errors): void
    {
        if ($node === null || preg_match('/^-?\d+(?:\.\d{1,2})?$/', $node->value()) !== 1) {
            $errors[] = 'Décimal eCH invalide.';
            return;
        }
        if ($percent && ((float) $node->value() < 0 || (float) $node->value() > 100)) {
            $errors[] = 'Pourcentage eCH hors intervalle.';
        }
    }

    /** @param list<string> $errors */
    private function date(?SecureXmlNode $node, array &$errors, string $field): void
    {
        $value = $node?->value() ?? '';
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            $errors[] = "Date eCH invalide: {$field}.";
        }
    }

    /** @param list<string> $values @param list<string> $errors */
    private function enumeration(?SecureXmlNode $node, array $values, array &$errors): void
    {
        if ($node === null || !in_array($node->value(), $values, true)) {
            $errors[] = 'Valeur d’énumération XSD invalide.';
        }
    }

    /** @param list<string> $errors */
    private function length(
        ?SecureXmlNode $node,
        int $min,
        int $max,
        array &$errors,
        string $field,
    ): void {
        $length = mb_strlen($node?->value() ?? '');
        if ($length < $min || $length > $max) {
            $errors[] = "Longueur XSD invalide: {$field}.";
        }
    }

    /** @param list<string> $errors */
    private function matches(
        ?SecureXmlNode $node,
        string $pattern,
        array &$errors,
        string $field,
    ): void {
        if ($node === null || preg_match($pattern, $node->value()) !== 1) {
            $errors[] = "Format XSD invalide: {$field}.";
        }
    }
}
