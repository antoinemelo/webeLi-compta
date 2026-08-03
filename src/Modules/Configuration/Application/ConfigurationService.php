<?php
declare(strict_types=1);

namespace Compta\Modules\Configuration\Application;

use Compta\Core\Audit\AuditLogger;
use Compta\Modules\Tva\VatConfigurationService;
use Compta\Modules\Tva\VatException;
use DateTimeImmutable;
use PDO;
use Throwable;

final class ConfigurationService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
        private readonly ModuleAccessService $modules,
    ) {
    }

    /** @return array<string,mixed> */
    public function read(int $organisationId, int $dossierId): array
    {
        $this->assertScope($organisationId, $dossierId);
        return [
            'identity' => $this->identity($organisationId, $dossierId),
            'modules' => $this->modules->modules($organisationId, $dossierId),
            'payment_terms' => $this->paymentTerms($organisationId, $dossierId),
            'payment_defaults' => $this->paymentDefaults(
                $organisationId,
                $dossierId
            ),
            'payment_accounting' => $this->paymentAccounting(
                $organisationId,
                $dossierId
            ),
            'audit' => $this->recentAudit($organisationId, $dossierId),
            'definitions' => [
                'contacts' => 'Le registre unique reste celui de Facturation.',
                'historical_values' => 'Les taux et conditions utilisés sont figés dans les documents et fiches.',
                'payment_due_date' => 'Date du document + délai, puis fin du mois obtenu si l’option est active.',
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function setupGuide(int $organisationId, int $dossierId): array
    {
        $this->assertScope($organisationId, $dossierId);
        $modules = array_fill_keys(
            $this->modules->enabledCodes($organisationId, $dossierId),
            true
        );
        $accountingEnabled = isset($modules['comptabilite']);
        $billingEnabled = isset($modules['facturation']);
        $treasuryEnabled = isset($modules['liquidites']);
        $payrollEnabled = isset($modules['salaires']);
        $parameters = $this->setupGuideParameters($dossierId);

        $identityReady = $this->count(
            'SELECT COUNT(*) FROM attributs_juridiques_organisation
             WHERE organisation_id = ?',
            [$organisationId]
        ) > 0;
        $periodsReady = $accountingEnabled
            && $this->exercisePeriodsReady($organisationId, $dossierId);
        $opening = $accountingEnabled
            ? $this->openingSetupState($organisationId, $dossierId)
            : ['validated' => false, 'zero_confirmable' => false];
        $treasuryReady = $treasuryEnabled && $this->count(
            'SELECT COUNT(*) FROM comptes_tresorerie
             WHERE organisation_id = ? AND dossier_id = ? AND actif = 1',
            [$organisationId, $dossierId]
        ) > 0;
        $billingAccountReady = $billingEnabled && $this->count(
            'SELECT COUNT(*) FROM dossiers d
             JOIN comptes_tresorerie t
               ON t.id = d.compte_tresorerie_facturation_id
             WHERE d.organisation_id = ? AND d.id = ?
               AND t.actif = 1 AND t.iban <> \'\'',
            [$organisationId, $dossierId]
        ) > 0;
        $vatReady = $accountingEnabled && $this->count(
            'SELECT COUNT(*) FROM tva_regimes
             WHERE organisation_id = ? AND dossier_id = ?',
            [$organisationId, $dossierId]
        ) > 0;
        $payrollRatesReady = $payrollEnabled && $this->count(
            'SELECT COUNT(*) FROM taux_salaires_annuels
             WHERE organisation_id = ? AND dossier_id = ?',
            [$organisationId, $dossierId]
        ) > 0;
        $payrollSettingsReady = $payrollEnabled
            && $this->count(
                'SELECT COUNT(*) FROM employeurs_salaires
                 WHERE organisation_id = ? AND dossier_id = ? AND actif = 1',
                [$organisationId, $dossierId]
            ) > 0
            && $this->count(
                'SELECT COUNT(*) FROM mapping_comptes_salaires
                 WHERE organisation_id = ? AND dossier_id = ?',
                [$organisationId, $dossierId]
            ) > 0;
        $paymentDefaultsReady = $billingEnabled
            && $this->activePaymentDefaultCount(
                $organisationId,
                $dossierId
            ) === 2;
        $currenciesReady = $this->count(
            'SELECT COUNT(*) FROM devises_dossier
             WHERE organisation_id = ? AND dossier_id = ? AND actif = 1',
            [$organisationId, $dossierId]
        ) > 0;

        $steps = [
            $this->guideStep(
                'identity',
                'Informations de l’organisation',
                'Ajoutez une identité juridique datée et sa source.',
                '/organisations-dossiers',
                true,
                true,
                $identityReady,
                $identityReady,
                false,
                'Ouvrir les informations'
            ),
            $this->guideStep(
                'exercises',
                'Exercices et périodes',
                'Contrôlez que chaque exercice est entièrement couvert par ses périodes, puis validez.',
                '/configuration/referentiels/exercises',
                true,
                $accountingEnabled,
                $periodsReady && isset($parameters['exercises']),
                $periodsReady,
                $periodsReady && !isset($parameters['exercises']),
                'Valider les exercices et périodes'
            ),
            $this->guideStep(
                'opening',
                'Plan comptable et ouverture',
                $opening['zero_confirmable']
                    ? 'Aucun mouvement n’existe encore : vous pouvez confirmer une ouverture à zéro.'
                    : 'Validez les soldes d’ouverture du premier exercice.',
                '/configuration/referentiels/plan?section=opening',
                true,
                $accountingEnabled,
                (bool) $opening['validated'] || isset($parameters['opening']),
                (bool) $opening['validated'] || (bool) $opening['zero_confirmable'],
                !(bool) $opening['validated']
                    && (bool) $opening['zero_confirmable']
                    && !isset($parameters['opening']),
                (bool) $opening['validated']
                    ? 'Consulter l’ouverture'
                    : ((bool) $opening['zero_confirmable']
                        ? 'Vérifier l’ouverture à zéro'
                        : 'Configurer les soldes d’ouverture')
            ),
            $this->guideStep(
                'treasury',
                'Comptes de trésorerie',
                'Ajoutez vos caisses et comptes bancaires si vous les utilisez.',
                '/configuration/referentiels/treasury',
                false,
                $treasuryEnabled,
                $treasuryReady,
                true,
                false,
                'Configurer la trésorerie'
            ),
            $this->guideStep(
                'billing_account',
                'Compte de facturation',
                'Choisissez, si nécessaire, le compte bancaire dont l’IBAN figurera sur les factures.',
                '/configuration',
                false,
                $billingEnabled,
                $billingAccountReady,
                true,
                false,
                'Configurer l’entité'
            ),
            $this->guideStep(
                'vat',
                'Régime TVA',
                'Vérifiez le statut, la méthode, la périodicité et la date d’effet du régime TVA.',
                '/configuration/referentiels/vat',
                true,
                $accountingEnabled,
                $vatReady && isset($parameters['vat']),
                $vatReady,
                $vatReady && !isset($parameters['vat']),
                'Configurer le régime TVA'
            ),
            $this->guideStep(
                'payroll_rates',
                'Taux annuels des charges sociales',
                'Importez ou saisissez les taux utiles aux salaires.',
                '/configuration/referentiels/payroll',
                false,
                $payrollEnabled,
                $payrollRatesReady,
                true,
                false,
                'Configurer les taux'
            ),
            $this->guideStep(
                'payroll_settings',
                'Paramètres salariaux',
                'Validez les heures hebdomadaires et l’affectation des comptes.',
                '/configuration/salaires',
                false,
                $payrollEnabled,
                $payrollSettingsReady,
                true,
                false,
                'Configurer les salaires'
            ),
            $this->guideStep(
                'payment_defaults',
                'Conditions de paiement',
                'Définissez une condition active par défaut pour les clients et pour les fournisseurs.',
                '/configuration/paiements',
                true,
                $billingEnabled,
                $paymentDefaultsReady,
                $paymentDefaultsReady,
                false,
                'Définir les conditions'
            ),
            $this->guideStep(
                'currencies',
                'Devises autorisées',
                'Ajoutez les devises utilisées par le dossier ; la devise de base est déjà disponible.',
                '/configuration/referentiels/currencies',
                false,
                true,
                $currenciesReady,
                true,
                false,
                'Configurer les devises'
            ),
        ];

        $requiredComplete = count(array_filter(
            $steps,
            static fn (array $step): bool => (
                $step['required']
                && $step['applicable']
                && !$step['completed']
            )
        )) === 0;
        $finished = isset($parameters['finished']);
        $cancelled = isset($parameters['cancelled']) && !$finished;
        $steps[] = $this->guideStep(
            'accounting',
            'Commencer à comptabiliser',
            'La configuration obligatoire est terminée. Vous pouvez ouvrir le journal comptable.',
            '/compta',
            true,
            $accountingEnabled,
            $requiredComplete && $finished,
            $requiredComplete,
            $requiredComplete && !$finished,
            'Terminer et ouvrir la comptabilité'
        );
        $applicable = array_values(array_filter(
            $steps,
            static fn (array $step): bool => $step['applicable']
        ));
        $completed = count(array_filter(
            $applicable,
            static fn (array $step): bool => $step['completed']
        ));

        return [
            'visible' => !$cancelled && (!$finished || !$requiredComplete),
            'cancelled' => $cancelled,
            'required_complete' => $requiredComplete,
            'finished' => $finished && $requiredComplete,
            'progress' => [
                'completed' => $completed,
                'total' => count($applicable),
            ],
            'steps' => $steps,
        ];
    }

    /** @return array<string,mixed> */
    public function confirmSetupGuideStep(
        int $organisationId,
        int $dossierId,
        string $step,
        int $actorId,
    ): array {
        if (!in_array($step, ['exercises', 'opening', 'vat', 'accounting'], true)) {
            throw new ConfigurationException('Étape de configuration inconnue.');
        }
        $state = $this->setupGuide($organisationId, $dossierId);
        $current = null;
        foreach ($state['steps'] as $candidate) {
            if ($candidate['code'] === $step) {
                $current = $candidate;
                break;
            }
        }
        if (
            $current === null
            || !$current['applicable']
            || !$current['ready']
        ) {
            throw new ConfigurationException(
                'Cette étape ne peut pas encore être validée.'
            );
        }
        $key = 'setup_guide.' . (
            $step === 'accounting' ? 'finished' : $step
        );
        $this->pdo->prepare(
            'INSERT INTO parametres_dossier (dossier_id, cle, valeur)
             VALUES (?, ?, ?)
             ON CONFLICT (dossier_id, cle) DO UPDATE SET valeur = excluded.valeur'
        )->execute([$dossierId, $key, (new DateTimeImmutable())->format(DATE_ATOM)]);
        $this->audit->log(
            'configuration.parcours_etape_validee',
            $actorId,
            $organisationId,
            $dossierId,
            'dossier',
            (string) $dossierId,
            ['etape' => $step]
        );
        return $this->setupGuide($organisationId, $dossierId);
    }

    /** @return array<string,mixed> */
    public function updateSetupGuideStatus(
        int $organisationId,
        int $dossierId,
        string $action,
        int $actorId,
    ): array {
        if (!in_array($action, ['cancel', 'resume'], true)) {
            throw new ConfigurationException('Action de parcours inconnue.');
        }
        $this->assertScope($organisationId, $dossierId);
        if ($action === 'cancel') {
            $this->pdo->prepare(
                'INSERT INTO parametres_dossier (dossier_id, cle, valeur)
                 VALUES (?, ?, ?)
                 ON CONFLICT (dossier_id, cle)
                 DO UPDATE SET valeur = excluded.valeur'
            )->execute([
                $dossierId,
                'setup_guide.cancelled',
                (new DateTimeImmutable())->format(DATE_ATOM),
            ]);
        } else {
            $this->pdo->prepare(
                'DELETE FROM parametres_dossier
                 WHERE dossier_id = ? AND cle = ?'
            )->execute([$dossierId, 'setup_guide.cancelled']);
        }
        $this->audit->log(
            $action === 'cancel'
                ? 'configuration.parcours_annule'
                : 'configuration.parcours_repris',
            $actorId,
            $organisationId,
            $dossierId,
            'dossier',
            (string) $dossierId,
            []
        );
        return $this->setupGuide($organisationId, $dossierId);
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function updateIdentity(
        int $organisationId,
        int $dossierId,
        array $data,
        int $actorId,
    ): array {
        $identity = $this->identity($organisationId, $dossierId);
        $organizationVersion = (int) ($data['organization_version'] ?? 0);
        $dossierVersion = (int) ($data['dossier_version'] ?? 0);
        if (
            $organizationVersion !== (int) $identity['organization']['version']
            || $dossierVersion !== (int) $identity['dossier']['version']
        ) {
            throw new ConfigurationException(
                'La configuration a été modifiée par un autre utilisateur.'
            );
        }
        $organization = $this->validateIdentity($data);
        foreach ([
            'legal_name', 'legal_form', 'uid', 'address_line1', 'address_line2',
            'postal_code', 'city', 'canton', 'country',
        ] as $historicalField) {
            if (
                $organization[$historicalField]
                !== (string) $identity['organization'][$historicalField]
            ) {
                throw new ConfigurationException(
                    'Modifiez l’identité juridique dans le registre des organisations '
                    . 'afin de conserver son historique daté et sa source.'
                );
            }
        }
        $billingAccountId = $data['billing_treasury_account_id'] ?? null;
        $billingAccountId = $billingAccountId === null
            ? null
            : (int) $billingAccountId;
        if ($billingAccountId !== null) {
            $billingAccount = $this->pdo->prepare(
                "SELECT iban FROM comptes_tresorerie
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?
                   AND actif = 1 AND iban <> ''"
            );
            $billingAccount->execute([
                $billingAccountId,
                $organisationId,
                $dossierId,
            ]);
            $iban = $billingAccount->fetchColumn();
            if (
                $iban === false
                || (!str_starts_with((string) $iban, 'CH')
                    && !str_starts_with((string) $iban, 'LI'))
            ) {
                throw new ConfigurationException(
                    'Choisissez un compte de trésorerie actif avec un IBAN CH ou LI.'
                );
            }
        }
        $vatExempt = (bool) ($data['vat_exempt'] ?? false);
        $vatEffectiveFrom = (string) ($data['vat_effective_from'] ?? '');
        $currency = mb_strtoupper(trim((string) ($data['base_currency'] ?? '')));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new ConfigurationException('La devise doit être un code ISO de trois lettres.');
        }
        $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $organizationVersion,
            $dossierVersion,
            $organization,
            $billingAccountId,
            $vatExempt,
            $vatEffectiveFrom,
            $currency,
            $actorId,
            $identity
        ): void {
            $updateOrganization = $this->pdo->prepare(
                'UPDATE organisations
                 SET nom = ?, telephone = ?, email = ?, site_web = ?,
                     modifie_le = datetime(\'now\'), version = version + 1
                 WHERE id = ? AND version = ?'
            );
            $updateOrganization->execute([
                $organization['name'],
                $organization['phone'],
                $organization['email'],
                $organization['website'],
                $organisationId,
                $organizationVersion,
            ]);
            $updateDossier = $this->pdo->prepare(
                'UPDATE dossiers
                 SET monnaie = ?, compte_tresorerie_facturation_id = ?,
                     version = version + 1
                 WHERE id = ? AND organisation_id = ? AND version = ?'
            );
            $updateDossier->execute([
                $currency,
                $billingAccountId,
                $dossierId,
                $organisationId,
                $dossierVersion,
            ]);
            $this->updateVatExemption(
                $organisationId,
                $dossierId,
                $vatExempt,
                $vatEffectiveFrom,
                $actorId
            );
            if ($updateOrganization->rowCount() !== 1 || $updateDossier->rowCount() !== 1) {
                throw new ConfigurationException(
                    'Conflit de version pendant l’enregistrement.'
                );
            }
            $changed = [];
            foreach ($organization as $key => $value) {
                if (($identity['organization'][$key] ?? null) !== $value) {
                    $changed[] = $key;
                }
            }
            if ($identity['dossier']['base_currency'] !== $currency) {
                $changed[] = 'base_currency';
            }
            if (
                ($identity['dossier']['billing_treasury_account_id'] ?? null)
                !== $billingAccountId
            ) {
                $changed[] = 'billing_treasury_account_id';
            }
            if (($identity['dossier']['vat_exempt'] ?? false) !== $vatExempt) {
                $changed[] = 'vat_exempt';
            }
            $this->audit->log(
                'configuration.identite_modifiee',
                $actorId,
                $organisationId,
                $dossierId,
                'organisation',
                (string) $organisationId,
                ['champs' => implode(',', $changed)]
            );
        });
        return $this->identity($organisationId, $dossierId);
    }

    public function setModule(
        int $organisationId,
        int $dossierId,
        string $code,
        bool $enabled,
        int $expectedVersion,
        int $actorId,
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE modules_dossier
             SET actif = ?, modifie_le = datetime(\'now\'), modifie_par = ?,
                 version = version + 1
             WHERE organisation_id = ? AND dossier_id = ?
               AND module_code = ? AND version = ?'
        );
        $stmt->execute([
            $enabled ? 1 : 0,
            $actorId,
            $organisationId,
            $dossierId,
            $code,
            $expectedVersion,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new ConfigurationException(
                'Module inconnu ou modifié par un autre utilisateur.'
            );
        }
        $this->audit->log(
            $enabled ? 'configuration.module_active' : 'configuration.module_desactive',
            $actorId,
            $organisationId,
            $dossierId,
            'module_dossier',
            $code,
            ['actif' => $enabled ? 1 : 0]
        );
    }

    /** @param array<string,mixed> $data */
    public function createPaymentTerm(
        int $organisationId,
        int $dossierId,
        array $data,
        int $actorId,
    ): int {
        $this->assertScope($organisationId, $dossierId);
        $code = mb_strtoupper(trim((string) ($data['code'] ?? '')));
        $label = trim((string) ($data['label'] ?? ''));
        $direction = (string) ($data['direction'] ?? 'tous');
        $days = (int) ($data['days'] ?? -1);
        $validFrom = (string) ($data['valid_from'] ?? '');
        $validUntil = trim((string) ($data['valid_until'] ?? ''));
        $this->date($validFrom);
        if ($validUntil !== '') {
            $this->date($validUntil);
        }
        if (
            preg_match('/^[A-Z0-9_-]{1,20}$/', $code) !== 1
            || $label === ''
            || !in_array($direction, ['client', 'fournisseur', 'tous'], true)
            || $days < 0
            || $days > 3650
            || ($validUntil !== '' && $validUntil < $validFrom)
        ) {
            throw new ConfigurationException('Condition de paiement invalide.');
        }
        if ((int) $this->scalar(
            'SELECT COUNT(*) FROM conditions_paiement
             WHERE dossier_id = ? AND code = ? AND date_debut = ?',
            [$dossierId, $code, $validFrom]
        ) > 0) {
            throw new ConfigurationException(
                'Une condition avec ce code existe déjà à cette date.'
            );
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO conditions_paiement
             (organisation_id, dossier_id, code, libelle, direction,
              delai_jours, fin_de_mois, date_debut, date_fin, cree_par)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $organisationId,
            $dossierId,
            $code,
            $label,
            $direction,
            $days,
            !empty($data['end_of_month']) ? 1 : 0,
            $validFrom,
            $validUntil === '' ? null : $validUntil,
            $actorId,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->audit->log(
            'configuration.condition_paiement_creee',
            $actorId,
            $organisationId,
            $dossierId,
            'condition_paiement',
            (string) $id,
            ['code' => $code, 'direction' => $direction]
        );
        return $id;
    }

    public function setPaymentDefault(
        int $organisationId,
        int $dossierId,
        string $direction,
        int $conditionId,
        string $validFrom,
        int $actorId,
    ): int {
        if (!in_array($direction, ['client', 'fournisseur'], true)) {
            throw new ConfigurationException('Direction de paiement invalide.');
        }
        $date = $this->date($validFrom);
        return $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $direction,
            $conditionId,
            $validFrom,
            $date,
            $actorId
        ): int {
            $condition = $this->pdo->prepare(
                'SELECT COUNT(*)
                 FROM conditions_paiement
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?
                   AND actif = 1
                   AND date_debut <= ?
                   AND (date_fin IS NULL OR date_fin >= ?)
                   AND (direction = ? OR direction = \'tous\')'
            );
            $condition->execute([
                $conditionId,
                $organisationId,
                $dossierId,
                $validFrom,
                $validFrom,
                $direction,
            ]);
            if ((int) $condition->fetchColumn() !== 1) {
                throw new ConfigurationException(
                    'La condition de paiement ne convient pas à ce dossier, cette direction ou cette date.'
                );
            }
            $latest = $this->pdo->prepare(
                'SELECT MAX(date_debut)
                 FROM defauts_conditions_paiement
                 WHERE organisation_id = ? AND dossier_id = ? AND direction = ?'
            );
            $latest->execute([$organisationId, $dossierId, $direction]);
            $latestDate = $latest->fetchColumn();
            if (is_string($latestDate) && $validFrom <= $latestDate) {
                throw new ConfigurationException(
                    'Le nouveau défaut doit prendre effet après le dernier défaut enregistré.'
                );
            }
            $this->pdo->prepare(
                'UPDATE defauts_conditions_paiement
                 SET date_fin = ?
                 WHERE organisation_id = ? AND dossier_id = ? AND direction = ?
                   AND date_fin IS NULL'
            )->execute([
                $date->modify('-1 day')->format('Y-m-d'),
                $organisationId,
                $dossierId,
                $direction,
            ]);
            $stmt = $this->pdo->prepare(
                'INSERT INTO defauts_conditions_paiement
                 (organisation_id, dossier_id, direction, condition_id,
                  date_debut, cree_par)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $organisationId,
                $dossierId,
                $direction,
                $conditionId,
                $validFrom,
                $actorId,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->audit->log(
                'configuration.condition_defaut_modifiee',
                $actorId,
                $organisationId,
                $dossierId,
                'defaut_condition_paiement',
                (string) $id,
                [
                    'direction' => $direction,
                    'condition_id' => $conditionId,
                    'date_debut' => $validFrom,
                ]
            );
            return $id;
        });
    }

    /** @return array{trigger:string,version:int} */
    public function setPaymentAccounting(
        int $organisationId,
        int $dossierId,
        string $trigger,
        int $version,
        int $actorId,
    ): array {
        if (!in_array($trigger, ['premier_lettrage', 'lettrage_complet'], true)) {
            throw new ConfigurationException(
                'Déclencheur de comptabilisation des paiements invalide.'
            );
        }
        return $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $trigger,
            $version,
            $actorId
        ): array {
            $this->assertScope($organisationId, $dossierId);
            $existing = $this->pdo->prepare(
                'SELECT version FROM politiques_comptabilisation_paiements
                 WHERE organisation_id = ? AND dossier_id = ?'
            );
            $existing->execute([$organisationId, $dossierId]);
            $current = $existing->fetchColumn();
            if ($current === false) {
                if ($version !== 0) {
                    throw new ConfigurationException(
                        'La politique de paiement a été modifiée par un autre utilisateur.'
                    );
                }
                $this->pdo->prepare(
                    'INSERT INTO politiques_comptabilisation_paiements
                     (organisation_id, dossier_id, declencheur, cree_par)
                     VALUES (?, ?, ?, ?)'
                )->execute([$organisationId, $dossierId, $trigger, $actorId]);
                $newVersion = 1;
            } else {
                $update = $this->pdo->prepare(
                    "UPDATE politiques_comptabilisation_paiements
                     SET declencheur = ?, modifie_le = datetime('now'),
                         modifie_par = ?, version = version + 1
                     WHERE organisation_id = ? AND dossier_id = ? AND version = ?"
                );
                $update->execute([
                    $trigger,
                    $actorId,
                    $organisationId,
                    $dossierId,
                    $version,
                ]);
                if ($update->rowCount() !== 1) {
                    throw new ConfigurationException(
                        'La politique de paiement a été modifiée par un autre utilisateur.'
                    );
                }
                $newVersion = $version + 1;
            }
            $this->audit->log(
                'configuration.comptabilisation_paiements_modifiee',
                $actorId,
                $organisationId,
                $dossierId,
                'politique_comptabilisation_paiements',
                (string) $dossierId,
                ['declencheur' => $trigger]
            );
            return ['trigger' => $trigger, 'version' => $newVersion];
        });
    }

    /** @return array<string,mixed> */
    private function identity(int $organisationId, int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.id AS organisation_id, o.nom, o.raison_sociale,
                    o.forme_juridique, o.numero_ide, o.adresse_ligne1,
                    o.adresse_ligne2, o.code_postal, o.localite, o.canton,
                    o.pays, o.telephone, o.email, o.site_web,
                    COALESCE(t.iban, \'\') AS billing_iban,
                    o.version AS organisation_version,
                    d.id AS dossier_id, d.nom AS dossier_nom, d.monnaie,
                    d.compte_tresorerie_facturation_id,
                    COALESCE((
                        SELECT r.statut FROM tva_regimes r
                        WHERE r.organisation_id = o.id AND r.dossier_id = d.id
                          AND r.date_debut <= date(\'now\')
                          AND COALESCE(r.date_fin, \'9999-12-31\') >= date(\'now\')
                        ORDER BY r.date_debut DESC, r.id DESC LIMIT 1
                    ), \'non_configure\') AS vat_status,
                    COALESCE((
                        SELECT r.date_debut FROM tva_regimes r
                        WHERE r.organisation_id = o.id AND r.dossier_id = d.id
                          AND r.date_debut <= date(\'now\')
                          AND COALESCE(r.date_fin, \'9999-12-31\') >= date(\'now\')
                        ORDER BY r.date_debut DESC, r.id DESC LIMIT 1
                    ), date(\'now\')) AS vat_effective_from,
                    d.version AS dossier_version
             FROM organisations o
             JOIN dossiers d ON d.organisation_id = o.id
             LEFT JOIN comptes_tresorerie t
               ON t.id = d.compte_tresorerie_facturation_id
             WHERE o.id = ? AND d.id = ?'
        );
        $stmt->execute([$organisationId, $dossierId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new ConfigurationException('Dossier introuvable.');
        }
        return [
            'organization' => [
                'id' => (int) $row['organisation_id'],
                'name' => (string) $row['nom'],
                'legal_name' => (string) $row['raison_sociale'],
                'legal_form' => (string) $row['forme_juridique'],
                'uid' => (string) $row['numero_ide'],
                'address_line1' => (string) $row['adresse_ligne1'],
                'address_line2' => (string) $row['adresse_ligne2'],
                'postal_code' => (string) $row['code_postal'],
                'city' => (string) $row['localite'],
                'canton' => (string) $row['canton'],
                'country' => (string) $row['pays'],
                'phone' => (string) $row['telephone'],
                'email' => (string) $row['email'],
                'website' => (string) $row['site_web'],
                'version' => (int) $row['organisation_version'],
            ],
            'dossier' => [
                'id' => (int) $row['dossier_id'],
                'name' => (string) $row['dossier_nom'],
                'base_currency' => (string) $row['monnaie'],
                'billing_treasury_account_id' =>
                    $row['compte_tresorerie_facturation_id'] === null
                        ? null
                        : (int) $row['compte_tresorerie_facturation_id'],
                'billing_iban' => (string) $row['billing_iban'],
                'billing_treasury_accounts' => $this->billingTreasuryAccounts(
                    $organisationId,
                    $dossierId
                ),
                'vat_status' => (string) $row['vat_status'],
                'vat_exempt' => (string) $row['vat_status'] === 'non_assujetti',
                'vat_effective_from' => (string) $row['vat_effective_from'],
                'version' => (int) $row['dossier_version'],
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function paymentTerms(int $organisationId, int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.*,
                    EXISTS (
                        SELECT 1 FROM defauts_conditions_paiement d
                        WHERE d.condition_id = c.id
                          AND d.date_debut <= date(\'now\')
                          AND (d.date_fin IS NULL OR d.date_fin >= date(\'now\'))
                    ) AS est_defaut
             FROM conditions_paiement c
             WHERE c.organisation_id = ? AND c.dossier_id = ?
             ORDER BY c.actif DESC, c.date_debut DESC, c.code'
        );
        $stmt->execute([$organisationId, $dossierId]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'label' => (string) $row['libelle'],
            'direction' => (string) $row['direction'],
            'days' => (int) $row['delai_jours'],
            'end_of_month' => (int) $row['fin_de_mois'] === 1,
            'valid_from' => (string) $row['date_debut'],
            'valid_until' => $row['date_fin'] === null ? null : (string) $row['date_fin'],
            'active' => (int) $row['actif'] === 1,
            'is_default' => (int) $row['est_defaut'] === 1,
            'version' => (int) $row['version'],
        ], $stmt->fetchAll());
    }

    /** @return list<array<string,mixed>> */
    private function paymentDefaults(int $organisationId, int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.id, d.direction, d.condition_id, d.date_debut, d.date_fin,
                    c.code, c.libelle
             FROM defauts_conditions_paiement d
             JOIN conditions_paiement c ON c.id = d.condition_id
             WHERE d.organisation_id = ? AND d.dossier_id = ?
             ORDER BY d.direction, d.date_debut DESC, d.id DESC'
        );
        $stmt->execute([$organisationId, $dossierId]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'direction' => (string) $row['direction'],
            'condition_id' => (int) $row['condition_id'],
            'condition_code' => (string) $row['code'],
            'condition_label' => (string) $row['libelle'],
            'valid_from' => (string) $row['date_debut'],
            'valid_until' => $row['date_fin'] === null
                ? null
                : (string) $row['date_fin'],
            'current' => (string) $row['date_debut'] <= date('Y-m-d')
                && ($row['date_fin'] === null
                    || (string) $row['date_fin'] >= date('Y-m-d')),
        ], $stmt->fetchAll());
    }

    /** @return array{trigger:string,version:int} */
    private function paymentAccounting(int $organisationId, int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT declencheur, version
             FROM politiques_comptabilisation_paiements
             WHERE organisation_id = ? AND dossier_id = ?'
        );
        $stmt->execute([$organisationId, $dossierId]);
        $row = $stmt->fetch();
        return $row === false
            ? ['trigger' => 'premier_lettrage', 'version' => 0]
            : [
                'trigger' => (string) $row['declencheur'],
                'version' => (int) $row['version'],
            ];
    }

    /** @return list<array<string,mixed>> */
    private function recentAudit(int $organisationId, int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.action, a.cible_type, a.cible_id, a.cree_le,
                    COALESCE(NULLIF(TRIM(u.prenom || \' \' || u.nom), \'\'), u.email) AS actor
             FROM audit_events a
             LEFT JOIN utilisateurs u ON u.id = a.utilisateur_id
             WHERE a.organisation_id = ? AND a.dossier_id = ?
             ORDER BY a.id DESC
             LIMIT 20'
        );
        $stmt->execute([$organisationId, $dossierId]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'action' => (string) $row['action'],
            'target_type' => (string) $row['cible_type'],
            'target_id' => (string) $row['cible_id'],
            'actor' => $row['actor'] === null ? 'Système' : (string) $row['actor'],
            'created_at' => (string) $row['cree_le'],
        ], $stmt->fetchAll());
    }

    public function clearAudit(int $organisationId, int $dossierId): int
    {
        $this->assertScope($organisationId, $dossierId);
        $stmt = $this->pdo->prepare(
            'DELETE FROM audit_events
             WHERE organisation_id = ? AND dossier_id = ?'
        );
        $stmt->execute([$organisationId, $dossierId]);
        return $stmt->rowCount();
    }

    /** @return list<array{id:int,label:string,iban:string,currency:string}> */
    private function billingTreasuryAccounts(
        int $organisationId,
        int $dossierId,
    ): array {
        $stmt = $this->pdo->prepare(
            "SELECT id, libelle, iban, monnaie
             FROM comptes_tresorerie
             WHERE organisation_id = ? AND dossier_id = ?
               AND actif = 1 AND iban <> ''
               AND (iban LIKE 'CH%' OR iban LIKE 'LI%')
             ORDER BY libelle, id"
        );
        $stmt->execute([$organisationId, $dossierId]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'label' => (string) $row['libelle'],
            'iban' => (string) $row['iban'],
            'currency' => (string) $row['monnaie'],
        ], $stmt->fetchAll());
    }

    private function updateVatExemption(
        int $organisationId,
        int $dossierId,
        bool $vatExempt,
        string $effectiveFrom,
        int $actorId,
    ): void {
        $stmt = $this->pdo->prepare(
            'SELECT statut FROM tva_regimes
             WHERE organisation_id = ? AND dossier_id = ?
               AND date_debut <= ?
               AND COALESCE(date_fin, \'9999-12-31\') >= ?
             ORDER BY date_debut DESC, id DESC LIMIT 1'
        );
        $stmt->execute([
            $organisationId,
            $dossierId,
            $effectiveFrom,
            $effectiveFrom,
        ]);
        $status = $stmt->fetchColumn();
        if ($vatExempt && $status === 'non_assujetti') {
            return;
        }
        if (!$vatExempt) {
            if ($status === false || $status === 'non_assujetti') {
                throw new ConfigurationException(
                    'Pour devenir assujetti, configurez le numéro TVA, la méthode '
                    . 'et les comptes dans Configuration → Référentiels → TVA.'
                );
            }
            return;
        }
        try {
            (new VatConfigurationService($this->pdo, $this->audit))->addRegime([
                'organisation_id' => $organisationId,
                'dossier_id' => $dossierId,
                'statut' => 'non_assujetti',
                'numero_tva' => '',
                'methode' => 'effective',
                'mode_decompte' => 'convenues',
                'periodicite' => 'annuelle',
                'date_debut' => $effectiveFrom,
                'date_fin' => null,
                'compte_impot_prealable_materiel_id' => null,
                'compte_impot_prealable_investissements_id' => null,
                'compte_tva_due_id' => null,
                'compte_decompte_tva_id' => null,
                'compte_corrections_id' => null,
                'fermer_precedent' => true,
            ], $actorId);
        } catch (VatException $exception) {
            throw new ConfigurationException($exception->getMessage());
        }
    }

    /** @param array<string,mixed> $data @return array<string,string> */
    private function validateIdentity(array $data): array
    {
        $fields = [
            'name', 'legal_name', 'legal_form', 'uid', 'address_line1',
            'address_line2', 'postal_code', 'city', 'canton', 'country',
            'phone', 'email', 'website',
        ];
        $values = [];
        foreach ($fields as $field) {
            $values[$field] = trim((string) ($data[$field] ?? ''));
        }
        $values['country'] = mb_strtoupper($values['country']);
        $values['canton'] = mb_strtoupper($values['canton']);
        $values['uid'] = mb_strtoupper($values['uid']);
        if ($values['name'] === '') {
            throw new ConfigurationException('Le nom de l’organisation est requis.');
        }
        if (
            $values['country'] !== ''
            && preg_match('/^[A-Z]{2}$/', $values['country']) !== 1
        ) {
            throw new ConfigurationException('Le pays doit être un code ISO de deux lettres.');
        }
        if (
            $values['email'] !== ''
            && filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new ConfigurationException('Adresse e-mail invalide.');
        }
        if (
            $values['website'] !== ''
            && filter_var($values['website'], FILTER_VALIDATE_URL) === false
        ) {
            throw new ConfigurationException('Adresse de site web invalide.');
        }
        return $values;
    }

    /**
     * @return array{
     *   code:string,title:string,description:string,path:string,required:bool,
     *   applicable:bool,completed:bool,ready:bool,confirmable:bool,
     *   action_label:string
     * }
     */
    private function guideStep(
        string $code,
        string $title,
        string $description,
        string $path,
        bool $required,
        bool $applicable,
        bool $completed,
        bool $ready,
        bool $confirmable,
        string $actionLabel,
    ): array {
        return [
            'code' => $code,
            'title' => $title,
            'description' => $description,
            'path' => $path,
            'required' => $required,
            'applicable' => $applicable,
            'completed' => $applicable && $completed,
            'ready' => $applicable && $ready,
            'confirmable' => $applicable && $confirmable,
            'action_label' => $actionLabel,
        ];
    }

    /** @return array<string,string> */
    private function setupGuideParameters(int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT cle, valeur FROM parametres_dossier
             WHERE dossier_id = ? AND cle LIKE \'setup_guide.%\''
        );
        $stmt->execute([$dossierId]);
        $parameters = [];
        foreach ($stmt->fetchAll() as $row) {
            $parameters[substr((string) $row['cle'], 12)] = (string) $row['valeur'];
        }
        return $parameters;
    }

    private function exercisePeriodsReady(
        int $organisationId,
        int $dossierId,
    ): bool {
        $exercises = $this->pdo->prepare(
            'SELECT e.id, e.date_debut, e.date_fin
             FROM exercices e
             JOIN dossiers d ON d.id = e.dossier_id
             WHERE d.organisation_id = ? AND e.dossier_id = ?
             ORDER BY e.date_debut, e.id'
        );
        $exercises->execute([$organisationId, $dossierId]);
        $rows = $exercises->fetchAll();
        if ($rows === []) {
            return false;
        }
        $periods = $this->pdo->prepare(
            'SELECT date_debut, date_fin FROM periodes
             WHERE organisation_id = ? AND dossier_id = ? AND exercice_id = ?
             ORDER BY date_debut, date_fin, id'
        );
        foreach ($rows as $exercise) {
            $periods->execute([
                $organisationId,
                $dossierId,
                (int) $exercise['id'],
            ]);
            $items = $periods->fetchAll();
            if ($items === []) {
                return false;
            }
            $expectedStart = (string) $exercise['date_debut'];
            foreach ($items as $period) {
                if (
                    (string) $period['date_debut'] !== $expectedStart
                    || (string) $period['date_fin'] > (string) $exercise['date_fin']
                ) {
                    return false;
                }
                $expectedStart = $this->date((string) $period['date_fin'])
                    ->modify('+1 day')
                    ->format('Y-m-d');
            }
            $expectedEnd = $this->date((string) $exercise['date_fin'])
                ->modify('+1 day')
                ->format('Y-m-d');
            if ($expectedStart !== $expectedEnd) {
                return false;
            }
        }
        return true;
    }

    /** @return array{validated:bool,zero_confirmable:bool} */
    private function openingSetupState(
        int $organisationId,
        int $dossierId,
    ): array {
        $exerciseId = (int) $this->scalar(
            "SELECT e.id FROM exercices e
             JOIN dossiers d ON d.id = e.dossier_id
             WHERE d.organisation_id = ? AND e.dossier_id = ?
             ORDER BY e.date_debut, e.id
             LIMIT 1",
            [$organisationId, $dossierId]
        );
        if ($exerciseId < 1) {
            return ['validated' => false, 'zero_confirmable' => false];
        }
        $validated = $this->count(
            "SELECT COUNT(*) FROM ecritures
             WHERE organisation_id = ? AND dossier_id = ? AND exercice_id = ?
               AND source_type = 'ouverture'
               AND statut IN ('validee', 'contre_passee')",
            [$organisationId, $dossierId, $exerciseId]
        ) > 0;
        $entryCount = $this->count(
            'SELECT COUNT(*) FROM ecritures
             WHERE organisation_id = ? AND dossier_id = ? AND exercice_id = ?',
            [$organisationId, $dossierId, $exerciseId]
        );
        return [
            'validated' => $validated,
            'zero_confirmable' => !$validated && $entryCount === 0,
        ];
    }

    private function activePaymentDefaultCount(
        int $organisationId,
        int $dossierId,
    ): int {
        return $this->count(
            "SELECT COUNT(DISTINCT d.direction)
             FROM defauts_conditions_paiement d
             JOIN conditions_paiement c ON c.id = d.condition_id
             WHERE d.organisation_id = ? AND d.dossier_id = ?
               AND d.direction IN ('client', 'fournisseur')
               AND d.date_debut <= date('now')
               AND COALESCE(d.date_fin, '9999-12-31') >= date('now')
               AND c.actif = 1
               AND c.date_debut <= date('now')
               AND COALESCE(c.date_fin, '9999-12-31') >= date('now')",
            [$organisationId, $dossierId]
        );
    }

    /** @param list<mixed> $params */
    private function count(string $sql, array $params): int
    {
        return (int) $this->scalar($sql, $params);
    }

    private function assertScope(int $organisationId, int $dossierId): void
    {
        if ((int) $this->scalar(
            'SELECT COUNT(*) FROM dossiers WHERE id = ? AND organisation_id = ?',
            [$dossierId, $organisationId]
        ) !== 1) {
            throw new ConfigurationException('Dossier introuvable.');
        }
    }

    private function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new ConfigurationException('Date invalide.');
        }
        return $date;
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /** @template T @param callable():T $callback @return T */
    private function transaction(callable $callback): mixed
    {
        $owns = !$this->pdo->inTransaction();
        if ($owns) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($owns) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
