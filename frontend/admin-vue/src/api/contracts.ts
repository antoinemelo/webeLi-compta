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

export type ConfigurationReferenceItem = {
  id: number;
  label: string;
  type: string;
  detail: string;
  active: boolean;
};

export type ConfigurationReference = {
  count: number;
  items: ConfigurationReferenceItem[];
  legacy_path: string;
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
  references: Record<string, ConfigurationReference>;
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
