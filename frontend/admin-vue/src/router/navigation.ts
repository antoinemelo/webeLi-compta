export type SubNavigationItem = {
  key: string;
  label: string;
  path: string;
  activePrefix?: string;
};

export const referenceNavigation: SubNavigationItem[] = [
  { key: 'plan', label: 'Plan comptable', path: '/configuration/referentiels/plan' },
  { key: 'treasury', label: 'Trésorerie', path: '/configuration/referentiels/treasury' },
  { key: 'currencies', label: 'Devises et change', path: '/configuration/referentiels/currencies' },
  { key: 'contacts', label: 'Débiteurs et créanciers', path: '/configuration/referentiels/contacts' },
  { key: 'vat', label: 'TVA', path: '/configuration/referentiels/vat' },
  { key: 'payroll', label: 'Charges sociales', path: '/configuration/referentiels/payroll' },
  { key: 'journals', label: 'Journaux', path: '/configuration/referentiels/journals' },
  { key: 'exercises', label: 'Exercices et périodes', path: '/configuration/referentiels/exercises' }
];

export const subNavigation: Record<string, SubNavigationItem[]> = {
  learning: [
    { key: 'catalogue', label: 'Catalogue', path: '/apprentissage' },
    { key: 'exercises', label: 'Exercices', path: '/apprentissage/exercices' },
    { key: 'tracking', label: 'Suivi', path: '/apprentissage/suivi' }
  ],
  liquidity: [
    { key: 'use', label: 'Utilisation', path: '/liquidites' },
    { key: 'bank', label: 'Rapprochement', path: '/liquidites/rapprochement' },
    { key: 'matching', label: 'Lettrage', path: '/liquidites/lettrage' },
    { key: 'payments', label: 'Paiements', path: '/liquidites/paiements' },
    { key: 'rates', label: 'Taux', path: '/liquidites/taux' }
  ],
  billing: [
    { key: 'sales', label: 'Ventes', path: '/facturation' },
    { key: 'purchases', label: 'Achats', path: '/facturation/achats' },
    { key: 'recurrences', label: 'Récurrences', path: '/facturation/recurrences' },
    { key: 'contacts', label: 'Contacts', path: '/facturation/contacts' },
    { key: 'aging', label: 'Échéancier', path: '/facturation/echeancier' }
  ],
  accounting: [
    { key: 'entries', label: 'Journalisation', path: '/compta' },
    { key: 'accounts', label: 'Extraits', path: '/compta/extraits' },
    { key: 'statements', label: 'États financiers', path: '/compta/etats' },
    { key: 'vat', label: 'TVA', path: '/compta/tva' },
    { key: 'closing', label: 'Clôture', path: '/compta/cloture' },
    { key: 'tax', label: 'Dossier fiscal', path: '/compta/fiscal' },
    { key: 'assets', label: 'Amortissements', path: '/compta/amortissements' },
    { key: 'consolidation', label: 'Consolidation', path: '/compta/consolidation' }
  ],
  payroll: [
    { key: 'employees', label: 'Employés', path: '/salaires' },
    { key: 'runs', label: 'Calculs', path: '/salaires/calculs' },
    { key: 'payslips', label: 'Fiches', path: '/salaires/fiches' },
    { key: 'annual', label: 'Annuels', path: '/salaires/annuels' }
  ],
  settings: [
    {
      key: 'structures',
      label: 'Organisations et dossiers',
      path: '/configuration/structures'
    },
    { key: 'entity', label: 'Entité', path: '/configuration' },
    { key: 'modules', label: 'Modules', path: '/configuration/modules' },
    { key: 'payments', label: 'Paiements', path: '/configuration/paiements' },
    {
      key: 'references',
      label: 'Référentiels',
      path: '/configuration/referentiels/plan',
      activePrefix: '/configuration/referentiels'
    },
    { key: 'payroll', label: 'Salaires', path: '/configuration/salaires' },
    { key: 'access', label: 'Accès', path: '/configuration/acces' },
    { key: 'audit', label: 'Audit', path: '/configuration/audit' }
  ]
};
