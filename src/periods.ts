export const MEAL_PERIODS = ['breakfast', 'brunch', 'lunch', 'dinner', 'late_night'] as const;
export type MealPeriod = typeof MEAL_PERIODS[number];

export function mealPeriod(name: string): MealPeriod | undefined {
  const fixed = name.toLowerCase().trim();
  if (fixed.includes('late') && fixed.includes('night')) return 'late_night';
  if (fixed.includes('brunch')) return 'brunch';
  if (fixed.includes('breakfast')) return 'breakfast';
  if (fixed.includes('lunch')) return 'lunch';
  if (fixed.includes('dinner')) return 'dinner';
  return undefined;
}

export function withMealPeriod<T extends { name: string }>(meal: T): T & { period?: MealPeriod } {
  const period = mealPeriod(meal.name);
  if (period === undefined) return meal;
  return { ...meal, period };
}
