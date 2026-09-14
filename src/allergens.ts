export const ALLERGEN_TOKENS = [
  'egg', 'fish', 'gluten', 'milk', 'peanut', 'sesame', 'shellfish', 'soy', 'treenut', 'wheat',
] as const;
export type AllergenToken = typeof ALLERGEN_TOKENS[number];

function normalizeLabel(label: string): string {
  return label.toLowerCase().trim().replace(/[_-]+/g, ' ').replace(/\s+/g, ' ');
}

export function allergenToken(label: string): AllergenToken | undefined {
  const fixed = normalizeLabel(label);
  if (fixed === 'egg') return 'egg';
  if (fixed === 'fish') return 'fish';
  if (fixed === 'milk' || fixed === 'dairy') return 'milk';
  if (fixed === 'peanut') return 'peanut';
  if (fixed === 'sesame') return 'sesame';
  if (fixed === 'shellfish') return 'shellfish';
  if (fixed === 'soy' || fixed === 'soybeans' || fixed === 'soybean') return 'soy';
  if (fixed === 'wheat') return 'wheat';
  if (fixed === 'gluten') return 'gluten';
  if (fixed === 'treenut' || fixed === 'tree nut' || fixed.startsWith('tree nut ')) return 'treenut';
  return undefined;
}

export function uniqueSortedAllergens(labels: Iterable<string>): string[] | undefined {
  const tokens = new Set<AllergenToken>();
  for (const label of labels) {
    const token = allergenToken(label);
    if (token) tokens.add(token);
  }
  if (tokens.size === 0) return undefined;
  return [...tokens].sort();
}

export function containsAllergen(value: unknown): boolean {
  return value === true || value === 1 || value === '1' ||
    typeof value === 'string' && value.trim().toLowerCase() === 'true';
}
