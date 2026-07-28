export const swissCantons = [
  { code: 'AG', label: 'Argovie' },
  { code: 'AI', label: 'Appenzell Rhodes-Intérieures' },
  { code: 'AR', label: 'Appenzell Rhodes-Extérieures' },
  { code: 'BE', label: 'Berne' },
  { code: 'BL', label: 'Bâle-Campagne' },
  { code: 'BS', label: 'Bâle-Ville' },
  { code: 'FR', label: 'Fribourg' },
  { code: 'GE', label: 'Genève' },
  { code: 'GL', label: 'Glaris' },
  { code: 'GR', label: 'Grisons' },
  { code: 'JU', label: 'Jura' },
  { code: 'LU', label: 'Lucerne' },
  { code: 'NE', label: 'Neuchâtel' },
  { code: 'NW', label: 'Nidwald' },
  { code: 'OW', label: 'Obwald' },
  { code: 'SG', label: 'Saint-Gall' },
  { code: 'SH', label: 'Schaffhouse' },
  { code: 'SO', label: 'Soleure' },
  { code: 'SZ', label: 'Schwytz' },
  { code: 'TG', label: 'Thurgovie' },
  { code: 'TI', label: 'Tessin' },
  { code: 'UR', label: 'Uri' },
  { code: 'VD', label: 'Vaud' },
  { code: 'VS', label: 'Valais' },
  { code: 'ZG', label: 'Zoug' },
  { code: 'ZH', label: 'Zurich' }
] as const;

export function cantonLabel(code: string): string {
  return swissCantons.find((canton) => canton.code === code)?.label || code || '—';
}
