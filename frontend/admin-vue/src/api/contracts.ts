export type ApiErrorItem = {
  code: string;
  message: string;
  correlation_id: string;
  fields?: Record<string, string[]>;
  details?: Record<string, string | number | boolean | null>;
};

export type Pagination = {
  page: number;
  per_page: number;
  total: number;
  pages: number;
  has_more: boolean;
};

export type ApiMeta = {
  contract_version: 'compta-api-v1';
  correlation_id: string;
  pagination?: Pagination;
  sort?: { field: string; order: 'asc' | 'desc' };
  filters?: Record<string, string>;
};

export type ApiEnvelope<T> = {
  data: T;
  meta: ApiMeta;
  errors: ApiErrorItem[];
};

export type ApiEndpoint = {
  key: string;
  method: 'GET' | 'POST';
  path: string;
};

export type NavigationItem = {
  key: string;
  label: string;
  path: string;
  permission: string | null;
};

export type Exercise = {
  id: number;
  label: string;
  start_date: string;
  end_date: string;
  status: 'ouvert' | 'ferme';
  version: number;
};

export type Dossier = {
  id: number;
  organization_id: number;
  organization_name: string;
  name: string;
  type: 'reel' | 'demo' | 'exercice';
};

export type ShellContext = {
  api: {
    version: 'compta-api-v1';
    base_path: string;
    csrf_header: 'X-CSRF-Token';
    endpoints: ApiEndpoint[];
  };
  instance: string;
  user: {
    id: number;
    email: string;
    first_name: string;
    last_name: string;
    name: string;
  };
  selection: null | {
    organization: { id: number; name: string; nature: 'reelle' | 'pedagogique' };
    dossier: { id: number; name: string; type: Dossier['type']; currency: string };
    exercise: null | Omit<Exercise, 'version'>;
  };
  permissions: string[];
  enabled_modules: string[];
  navigation: NavigationItem[];
  csrf_token: string;
};

export type ConfigurationModule = {
  code: 'apprentissage' | 'liquidites' | 'facturation' | 'comptabilite' | 'salaires';
  label: string;
  description: string;
  enabled: boolean;
  version: number;
  updated_at: string | null;
};

export type PaymentTerm = {
  id: number;
  code: string;
  label: string;
  direction: 'client' | 'fournisseur' | 'tous';
  days: number;
  end_of_month: boolean;
  valid_from: string;
  valid_until: string | null;
  active: boolean;
  is_default: boolean;
  version: number;
};

export type ConfigurationPayload = {
  identity: {
    organization: {
      id: number;
      name: string;
      legal_name: string;
      legal_form: string;
      uid: string;
      address_line1: string;
      address_line2: string;
      postal_code: string;
      city: string;
      canton: string;
      country: string;
      phone: string;
      email: string;
      website: string;
      billing_iban: string;
      version: number;
    };
    dossier: {
      id: number;
      name: string;
      base_currency: string;
      version: number;
    };
  };
  modules: ConfigurationModule[];
  payment_terms: PaymentTerm[];
  audit: Array<{
    id: number;
    action: string;
    target_type: string;
    target_id: string;
    actor: string;
    created_at: string;
  }>;
  definitions: {
    contacts: string;
    historical_values: string;
    payment_due_date: string;
  };
};

export type ManagedReferencesPayload = {
  contacts: Array<{
    id: number;
    type: 'entreprise' | 'personne';
    company: string;
    first_name: string;
    last_name: string;
    email: string;
    phone: string;
    payment_iban: string;
    payment_bic: string;
    language: 'fr' | 'de' | 'it' | 'en';
    roles: Array<'client' | 'fournisseur' | 'employe' | 'autre'>;
    address_line1: string;
    address_line2: string;
    postal_code: string;
    city: string;
    country: string;
    version: number;
  }>;
  vat: {
    codes: Array<{
      id: number;
      code: string;
      label: string;
      treatment: string;
      nature: string;
      legal_rate_id: number | null;
      legal_rate_label: string;
      rate_bp: number | null;
      deduction_right: boolean;
      default_deduction_bp: number;
      afc_box: string;
      account_id: number | null;
      account: string;
      valid_from: string;
      valid_until: string | null;
      active: boolean;
      used: boolean;
    }>;
    legal_rates: Array<{
      id: number;
      category: string;
      label: string;
      rate_bp: number;
      valid_from: string;
      valid_until: string | null;
      source_url: string;
      verified_on: string;
    }>;
    accounts: Array<{ id: number; number: string; label: string }>;
  };
  payroll: {
    fields: string[];
    rates: Array<Record<string, string | number | null>>;
    suggested_rates: Record<string, string | number>;
  };
  treasury: {
    accounts: Array<{
      id: number;
      ledger_account_id: number;
      ledger_account_number: string;
      label: string;
      type: 'banque' | 'poste' | 'caisse' | 'carte';
      iban: string;
      bic: string;
      currency: string;
      accounting_multiplier: -1 | 1;
      active: boolean;
      version: number;
    }>;
    ledger_accounts: Array<{ id: number; number: string; label: string }>;
  };
  accounting_setup: {
    journal_types: string[];
    journals: Array<{
      id: number;
      code: string;
      label: string;
      type: string;
      active: boolean;
      version: number;
    }>;
    exercises: Array<{
      id: number;
      label: string;
      start_date: string;
      end_date: string;
      status: 'ouvert' | 'ferme';
      version: number;
    }>;
    periods: Array<{
      id: number;
      exercise_id: number;
      exercise: string;
      label: string;
      start_date: string;
      end_date: string;
      status: 'ouverte' | 'fermee';
      version: number;
    }>;
  };
  access: {
    roles: Array<{ id: number; code: string; label: string }>;
    users: Array<{
      id: number;
      email: string;
      name: string;
      active: boolean;
      dossier_role_ids: number[];
      inherited_roles: string[];
    }>;
  };
  capabilities: {
    contacts: boolean;
    vat: boolean;
    payroll: boolean;
    treasury: boolean;
    accounting_setup: boolean;
    access: boolean;
  };
};

export type ExpenseLine = {
  id: number;
  label: string;
  quantity_milli: number;
  unit_price_cents: number;
  input_mode: 'net' | 'brut';
  account_id: number;
  vat_code_id: number;
  net_cents: number;
  vat_cents: number;
  gross_cents: number;
};

export type ExpenseItem = {
  id: number;
  number: string;
  external_number: string;
  status: 'brouillon' | 'a_approuver' | 'approuve' | 'comptabilise' | 'annule';
  version: number;
  contact_id: number;
  supplier: string;
  document_date: string;
  due_date: string;
  currency: string;
  net_cents: number;
  vat_cents: number;
  gross_cents: number;
  allocated_cents: number;
  open_cents: number;
  attachment: null | { id: number; name: string; type: string; size: number };
  entry_id: number | null;
  reversal_entry_id: number | null;
  lines: ExpenseLine[];
};

export type RecurringExpense = {
  id: number;
  label: string;
  supplier: string;
  frequency: 'hebdomadaire' | 'mensuelle' | 'trimestrielle' | 'annuelle';
  interval: number;
  next_date: string;
  end_date: string | null;
  status: 'actif' | 'pause' | 'termine';
  generations: number;
  version: number;
};

export type ExpensesPayload = {
  expenses: ExpenseItem[];
  recurrences: RecurringExpense[];
  capabilities: { manage: boolean; approve: boolean; post: boolean };
  catalog: {
    suppliers: Array<{ id: number; label: string }>;
    accounts: Array<{ id: number; number: string; label: string }>;
    vat_codes: Array<{ id: number; code: string; label: string }>;
    exercises: Array<{ id: number; label: string }>;
    journals: Array<{ id: number; code: string; label: string }>;
  };
};

export type TreasuryWorkspace = {
  treasury_accounts: Array<{
    id: number; label: string; type: string; iban: string; bic: string;
    currency: string; ledger_account_id: number; ledger_number: string;
  }>;
  imports: Array<{
    id: number; treasury_account_id: number; format: string; filename: string;
    source_hash: string; date_start: string; date_end: string; status: string;
    total_count: number; imported_count: number; duplicate_count: number;
    created_at: string; confirmed_at: string | null;
  }>;
  bank_lines: Array<{
    id: number; treasury_account_id: number; import_id: number;
    booking_date: string; value_date: string; label: string; counterparty: string;
    communication: string; reference: string; amount_cents: number;
    fee_cents: number; currency: string; reconciliation_id: number | null;
  }>;
  accounting_lines: Array<{
    id: number; treasury_account_id: number; entry_id: number;
    entry_number: string; accounting_date: string; label: string;
    amount_cents: number; reconciliation_id: number | null;
  }>;
  reconciliations: Array<{
    id: number; treasury_account_id: number; label: string;
    bank_total_cents: number; accounting_total_cents: number;
    difference_cents: number; tolerance_cents: number;
    status: 'confirme' | 'annule'; created_at: string;
    cancelled_at: string | null; version: number;
    bank_line_count: number; accounting_line_count: number;
  }>;
  suggestions: Array<{
    id: number; bank_line_id: number; counterpart_account_id: number;
    label: string; confidence: number; reason: string;
    status: 'proposee' | 'acceptee' | 'refusee'; entry_id: number | null;
  }>;
  payments: Array<Record<string, unknown> & {
    id: number; contact_id: number; sens: 'encaissement' | 'decaissement';
    date_paiement: string; montant_centimes: number; reference: string;
    alloue_centimes: number; non_alloue_centimes: number; statut: string;
  }>;
  allocations: Array<Record<string, unknown> & {
    id: number; paiement_id: number | null; document_id: number;
    montant_centimes: number; statut: 'valide' | 'annule';
    document_numero: string; contact: string; date_source?: string;
  }>;
  open_documents: Array<{
    id: number; number: string; type: 'facture_client' | 'facture_fournisseur';
    workflow: string; contact_id: number; due_date: string; currency: string;
    gross_cents: number; allocated_cents: number; open_cents: number; contact: string;
  }>;
  payable_debts: Array<{
    id: number; number: string; external_number: string; contact_id: number;
    due_date: string; currency: string; open_cents: number; supplier: string;
    iban: string; bic: string;
  }>;
  outgoing_batches: Array<{
    id: number; treasury_account_id: number; message_id: string;
    execution_date: string; currency: string; order_count: number;
    total_cents: number; status: 'prepare' | 'exporte' | 'confirme';
    pain_version: string; hash: string; created_at: string;
    exported_at: string | null; confirmed_at: string | null;
    bank_line_id: number | null; reconciliation_id: number | null;
    fee_cents: number; version: number;
    orders: Array<{
      id: number; document_id: number; contact_id: number; beneficiary: string;
      iban: string; bic: string; reference: string; amount_cents: number;
      currency: string; status: string; payment_id: number | null;
    }>;
  }>;
  catalog: {
    exercises: Array<{ id: number; libelle: string; date_debut: string; date_fin: string; statut: string }>;
    journals: Array<{ id: number; code: string; libelle: string; type: string }>;
    accounts: Array<{ id: number; numero: string; libelle: string; sens_normal: string }>;
    contacts: Array<{ id: number; label: string; roles: string }>;
    treasury_accounts: Array<{
      id: number; label: string; type: string; iban: string; bic: string;
      currency: string; ledger_account_id: number; ledger_number: string;
    }>;
  };
  capabilities: {
    import: boolean; reconcile: boolean; suggest: boolean;
    accept_suggestion: boolean; match: boolean;
    prepare_payments: boolean; export_payments: boolean; confirm_payments: boolean;
  };
  definitions: { banking: string; matching: string; pain001: string };
};

export type AccountingWorkspace = {
  exercise: {
    id: number;
    label: string;
    start_date: string;
    end_date: string;
  };
  catalog: {
    exercises: Array<{
      id: number;
      label: string;
      start_date: string;
      end_date: string;
      status: string;
    }>;
    journals: Array<{ id: number; code: string; label: string; type: string }>;
    accounts: Array<{
      id: number;
      number: string;
      label: string;
      normal_side: 'debit' | 'credit';
    }>;
  };
  chart: {
    types: Array<{
      id: number;
      code: string;
      label: string;
      order: number;
      version: number;
    }>;
    credit_prefixes: string[];
    rubrics: Array<{
      id: number;
      code: string;
      label: string;
      structure_level: 'classe' | 'groupe_principal' | 'groupe' | 'sous_groupe';
      type: 'actif' | 'passif' | 'produit' | 'charge' | 'hors_bilan';
      parent_id: number | null;
      path: string;
      order: number;
      version: number;
    }>;
    accounts: Array<{
      id: number;
      number: string;
      label: string;
      type: string;
      normal_side: 'debit' | 'credit';
      sense_mode: 'automatique' | 'debit' | 'credit';
      rubric_id: number | null;
      rubric_path: string;
      active: boolean;
      order: number;
      version: number;
    }>;
  };
  opening: {
    id: number;
    status: 'absent' | 'brouillon' | 'validee' | 'contre_passee';
    number: string;
    version: number;
    soldes: Record<string, number>;
    total_debit_centimes: number;
    total_credit_centimes: number;
  };
  journal: {
    items: Array<{
      id: number;
      numero: string;
      date_comptable: string;
      libelle: string;
      reference: string;
      statut: string;
      journal: string;
      comptes_debit: string;
      comptes_credit: string;
      debit_centimes: number;
      credit_centimes: number;
    }>;
    total: number;
    page: number;
    par_page: number;
    pages: number;
  };
  ledger: null | {
    items: Array<{
      ecriture_id: number;
      numero: string;
      date_comptable: string;
      journal: string;
      reference: string;
      libelle: string;
      debit_centimes: number;
      credit_centimes: number;
      solde_centimes: number;
    }>;
    account: {
      id: number;
      numero: string;
      libelle: string;
      sens_normal: 'debit' | 'credit';
    };
    total_debit_centimes: number;
    total_credit_centimes: number;
    solde_centimes: number;
  };
  reports: {
    parameters: {
      exercise_id: number; date_start: string; date_end: string;
      ledger_statuses: string[];
    };
    general_ledger: {
      items: Array<{
        id: number; numero: string; libelle: string; sens_normal: string;
        initial_centimes: number; debit_centimes: number;
        credit_centimes: number; solde_centimes: number;
      }>;
    };
    trial_balance: {
      items: Array<{
        id: number; numero: string; libelle: string; type: string;
        rubrique_chemin: string; debit_centimes: number;
        credit_centimes: number; solde_centimes: number;
      }>;
      total_debit_centimes: number; total_credit_centimes: number;
      equilibree: boolean;
    };
    balance_sheet: {
      items: Array<{
        id: number; numero: string; libelle: string;
        type: 'actif' | 'passif' | 'fonds_propres';
        rubrique_chemin: string; solde_centimes: number;
      }>;
      total_actif_centimes: number; total_passif_centimes: number;
      equilibre: boolean;
    };
    income_statement: {
      items: Array<{
        number: string; label: string; type: string; rubric_path: string;
        current_cents: number; previous_cents: number; delta_cents: number;
      }>;
      current: {
        label: string; products_cents: number; expenses_cents: number;
        result_cents: number;
      };
      previous: {
        exercise_id: number | null; label: string | null;
        products_cents: number; expenses_cents: number; result_cents: number;
      };
      delta: {
        products_cents: number; expenses_cents: number; result_cents: number;
      };
    };
    cash_flow: {
      method: string; method_label: string; date_start: string; date_end: string;
      opening_cash_cents: number; inflows_cents: number; outflows_cents: number;
      net_change_cents: number; closing_cash_cents: number;
      reconciled_closing_cents: number; reconciliation_difference_cents: number;
      classification_status: string;
      accounts: Array<{
        id: number; label: string; currency: string; ledger_number: string;
        opening_cents: number; closing_cents: number; change_cents: number;
      }>;
      items: Array<{
        entry_id: number; number: string; date: string; label: string;
        category: string; amount_cents: number;
      }>;
    };
    controls: {
      debit_equals_credit: boolean; balance_sheet_balanced: boolean;
      trial_result_cents: number; income_result_cents: number;
      balance_result_cents: number; result_reconciled: boolean;
      cash_reconciled: boolean;
    };
    definitions: { read_only: string; cash_flow: string; comparison: string };
  };
  vat: {
    regime: null | {
      id: number; status: string; vat_number: string; method: string;
      reporting_mode: string; frequency: string; start_date: string;
      end_date: string | null; regulatory_source: string; verified_on: string;
    };
    periods: Array<{
      id: number; start_date: string; end_date: string; status: string;
      version: number; regime_id: number;
    }>;
    statements: Array<{
      id: number; period_id: number; status: string; submission_type: number;
      correction_number: number; start_date: string; end_date: string;
      turnover_cents: number; vat_due_cents: number; input_tax_cents: number;
      corrections_cents: number; balance_cents: number;
    }>;
    selected_statement: null | {
      summary: AccountingWorkspace['vat']['statements'][number];
      boxes: Array<{ code: string; label: string; amount_cents: number }>;
      reconciliation: Record<string, number | boolean | string>;
      sources: Array<{
        vat_line_id: number; entry_id: number; entry_number: string;
        date: string; label: string; box: string; base_cents: number;
        vat_cents: number; input_tax_cents: number; gross_cents: number;
      }>;
      exports: Array<{
        id: number; format: string; schema_version: string; hash: string;
        schema_valid: boolean; transmitted: false; created_at: string;
      }>;
    };
    standard: {
      format: string; version: string; verified_on: string;
      transmission: 'manuelle'; transmitted_by_application: false;
    };
  };
  closing: {
    periods: Array<{
      id: number; label: string; start_date: string; end_date: string;
      status: 'ouverte' | 'fermee'; version: number;
    }>;
    automatic_controls: Array<{
      code: string; label: string; passed: boolean; detail: string;
    }>;
    manual_controls: Array<{
      code: string; label: string;
      status: 'a_faire' | 'termine' | 'non_applicable';
      note: string; version: number; updated_at: string | null;
    }>;
    can_close: boolean;
    archives: Array<{
      id: number; type: 'cloture' | 'dossier_fiscal';
      start_date: string; end_date: string; parameters_hash: string;
      ledger_hash: string; hash: string; created_at: string;
    }>;
    definition: string;
  };
  tax_file: {
    status: 'preparatoire'; official_declaration: false; disclaimer: string;
    period: { start_date: string; end_date: string };
    bank_reconciliation: {
      bank_lines: number; unmatched_lines: number; unmatched_cents: number;
    };
    supporting_documents: {
      financial_documents: number; linked_attachments: number;
      missing_supplier_attachments: number;
    };
    vat: {
      regime: AccountingWorkspace['vat']['regime']; period_count: number;
      statement_count: number;
      latest_statement: AccountingWorkspace['vat']['statements'][number] | null;
    };
    adjustments: Array<{
      id: number; label: string; nature: string; amount_cents: number;
      note: string; status: 'propose' | 'valide' | 'ecarte';
      created_at: string; updated_at: string | null; version: number;
    }>;
  };
  capabilities: {
    edit: boolean; validate: boolean; setup: boolean; export: boolean;
    vat_setup: boolean; vat_prepare: boolean; vat_control: boolean;
    vat_export: boolean; vat_declare: boolean;
  };
};

export type DashboardAging = {
  not_due: number;
  days_1_30: number;
  days_31_60: number;
  days_61_90: number;
  days_91_plus: number;
};

export type DashboardOpenItems = {
  open_cents: number;
  overdue_cents: number;
  open_count: number;
  overdue_count: number;
  aging: DashboardAging;
};

export type DashboardProjection = {
  scope: {
    exercise: Omit<Exercise, 'version'>;
    period: null | {
      id: number;
      label: string;
      start_date: string;
      end_date: string;
      status: 'ouverte' | 'fermee';
    };
    as_of_date: string;
    base_currency: string;
  };
  treasury: {
    accounts: Array<{
      id: number;
      label: string;
      type: 'banque' | 'poste' | 'caisse' | 'carte';
      currency: string;
      ledger_account: { id: number; number: string; label: string };
      accounting_balance_cents: number;
      bank_balance_cents: number | null;
      bank_balance_date: string | null;
      bank_balance_currency: string | null;
      difference_cents: number | null;
      comparable_in_base_currency: boolean;
    }>;
    accounting_balance_cents: number;
    bank_balance_cents: number | null;
    difference_cents: number | null;
    bank_balance_coverage: {
      comparable_accounts: number;
      total_accounts: number;
      comparable_accounting_balance_cents: number;
    };
  };
  profit_and_loss: {
    revenue_cents: number;
    expenses_cents: number;
    result_cents: number;
  };
  open_items: {
    receivables: DashboardOpenItems;
    payables: DashboardOpenItems;
  };
  operations: {
    unreconciled_bank_lines: {
      count: number;
      net_cents: number;
      absolute_cents: number;
    };
    payments_to_process: {
      count: number;
      amount_cents: number;
      incoming_count: number;
      incoming_cents: number;
      outgoing_count: number;
      outgoing_cents: number;
    };
  };
  recent_entries: Array<{
    id: number;
    number: string;
    date: string;
    label: string;
    reference: string;
    journal: string;
    status: 'validee' | 'contre_passee';
    amount_cents: number;
    source: { type: string; id: string; path: string };
  }>;
  empty_state: {
    is_empty: boolean;
    code: 'NO_ACTIVITY_AT_DATE' | null;
    message: string | null;
  };
  calculation: {
    calculated_at: string;
    ledger_statuses: string[];
    revenue_definition: string;
    expenses_definition: string;
    open_items_definition: string;
    overdue_definition: string;
    aging_buckets: string[];
    recent_entry_limit: number;
    cache: false;
  };
};

export type BillingDocument = {
  id: number;
  number: string;
  external_number: string;
  type: 'facture_client' | 'avoir_client' | 'facture_fournisseur' | 'avoir_fournisseur';
  direction: 'sales' | 'purchases';
  status: string;
  payment_state: string;
  version: number;
  contact_id: number;
  contact: string;
  document_date: string;
  due_date: string;
  currency: string;
  net_cents: number;
  vat_cents: number;
  gross_cents: number;
  allocated_cents: number;
  open_cents: number;
  reminder_count: number;
  entry_id: number | null;
  origin_document_id: number | null;
  scor_reference: string;
  has_archived_pdf: boolean;
  lines: Array<{
    id: number;
    label: string;
    quantity_milli: number;
    unit_price_cents: number;
    input_mode: 'net' | 'brut';
    account_id: number;
    vat_code_id: number;
    net_cents: number;
    vat_cents: number;
    gross_cents: number;
  }>;
};

export type BillingAgingSide = {
  buckets: {
    not_due: number;
    days_0_30: number;
    days_31_60: number;
    days_61_90: number;
    days_91_plus: number;
  };
  open_documents_cents: number;
  unallocated_payments_cents: number;
  net_open_cents: number;
  item_count: number;
};

export type BillingContact = {
  id: number;
  type: 'entreprise' | 'personne';
  company: string;
  first_name: string;
  last_name: string;
  label: string;
  email: string;
  phone: string;
  iban: string;
  bic: string;
  language: string;
  roles: string[];
  version: number;
  address: {
    line1: string;
    line2: string;
    postal_code: string;
    city: string;
    country: string;
  };
  balance: {
    receivable_cents: number;
    payable_cents: number;
    net_cents: number;
  };
};

export type BillingPayload = {
  reference_date: string;
  filters: {
    direction: string;
    status: string;
    search: string;
    contact_id: number | null;
  };
  documents: BillingDocument[];
  aging: {
    receivables: BillingAgingSide;
    payables: BillingAgingSide;
  };
  contacts: BillingContact[];
  contact_360: null | {
    contact_id: number;
    reference_date: string;
    documents: BillingDocument[];
    payments: BillingPayment[];
    aging: {
      receivables: BillingAgingSide;
      payables: BillingAgingSide;
    };
    balance: BillingContact['balance'];
  };
  payments: BillingPayment[];
  allocations: Array<Record<string, string | number | null>>;
  recurrences: Array<{
    id: number;
    type: 'facture_client' | 'facture_fournisseur';
    label: string;
    contact_id: number;
    contact: string;
    frequency: string;
    interval: number;
    next_date: string;
    end_date: string | null;
    due_days: number;
    status: string;
    generation_count: number;
    version: number;
  }>;
  reminders: Array<{
    id: number;
    document_id: number;
    level: number;
    channel: string;
    note: string;
    reminded_at: string;
    document_number: string;
  }>;
  catalog: {
    accounts: Array<{ id: number; number: string; label: string }>;
    vat_codes: Array<{
      id: number;
      code: string;
      label: string;
      nature: 'collectee' | 'prealable' | 'acquisition' | 'non_taxable' | 'correction';
      valid_from: string;
      valid_until: string | null;
    }>;
    exercises: Array<{ id: number; label: string }>;
    journals: Array<{ id: number; code: string; label: string }>;
  };
  capabilities: {
    manage: boolean;
    issue: boolean;
    post: boolean;
    pay: boolean;
    remind: boolean;
  };
  definitions: Record<string, string>;
};

export type BillingPayment = {
  id: number;
  contact_id: number;
  contact: string;
  direction: 'encaissement' | 'decaissement';
  payment_date: string;
  amount_cents: number;
  allocated_cents: number;
  unallocated_cents: number;
  currency: string;
  reference: string;
  status: string;
};
