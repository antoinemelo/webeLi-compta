export type SubNavigationItem = {
  key: string;
  label: string;
  path: string;
};

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
    { key: 'payments', label: 'Paiements', path: '/liquidites/paiements' }
  ],
  billing: [
    { key: 'sales', label: 'Ventes', path: '/facturation' },
    { key: 'purchases', label: 'Achats', path: '/facturation/achats' },
    { key: 'contacts', label: 'Contacts', path: '/facturation/contacts' },
    { key: 'aging', label: 'Échéancier', path: '/facturation/echeancier' }
  ],
  accounting: [
    { key: 'entries', label: 'Journalisation', path: '/compta' },
    { key: 'accounts', label: 'Extraits', path: '/compta/extraits' },
    { key: 'statements', label: 'États financiers', path: '/compta/etats' },
    { key: 'vat', label: 'TVA', path: '/compta/tva' },
    { key: 'assets', label: 'Amortissements', path: '/compta/amortissements' }
  ],
  payroll: [
    { key: 'employees', label: 'Employés', path: '/salaires' },
    { key: 'runs', label: 'Calculs', path: '/salaires/calculs' },
    { key: 'payslips', label: 'Fiches', path: '/salaires/fiches' },
    { key: 'annual', label: 'Annuels', path: '/salaires/annuels' }
  ],
  settings: [
    { key: 'entity', label: 'Entité', path: '/configuration' },
    { key: 'accounting', label: 'Comptabilité', path: '/configuration/comptabilite' },
    { key: 'taxes', label: 'TVA et charges', path: '/configuration/taux' },
    { key: 'access', label: 'Accès', path: '/configuration/acces' }
  ]
};
