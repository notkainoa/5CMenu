export const DIET_FLAGS = [
  'vegan', 'vegetarian', 'glutenFree', 'halal', 'kosher',
  'mindful', 'plantBased', 'containsPork', 'containsBeef', 'containsPoultry',
] as const;
export type DietFlag = typeof DIET_FLAGS[number];

export function copyDietFlags<T extends Partial<Record<DietFlag, boolean>>>(from: T, to: T): void {
  for (const flag of DIET_FLAGS) {
    const value = from[flag];
    if (value !== undefined) to[flag] = value;
  }
}
