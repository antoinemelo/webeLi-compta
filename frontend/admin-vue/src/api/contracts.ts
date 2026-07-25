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
  navigation: NavigationItem[];
  csrf_token: string;
};
