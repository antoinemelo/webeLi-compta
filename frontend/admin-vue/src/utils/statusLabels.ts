export function exerciseStatusLabel(status: string): string {
  return status === 'ferme' ? 'Fermé' : 'Ouvert';
}

export function periodStatusLabel(status: string): string {
  return status === 'fermee' ? 'Fermée' : 'Ouverte';
}
