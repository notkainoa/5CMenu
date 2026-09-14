import assert from 'node:assert/strict';
import test from 'node:test';
import { mealPeriod, withMealPeriod } from '../src/periods';

test('mealPeriod maps school labels onto one lowercase token', () => {
  assert.equal(mealPeriod('DINNER'), 'dinner');
  assert.equal(mealPeriod('Dinner'), 'dinner');
  assert.equal(mealPeriod('Continental Breakfast'), 'breakfast');
  assert.equal(mealPeriod('Brunch'), 'brunch');
  assert.equal(mealPeriod('Late Night'), 'late_night');
  assert.equal(mealPeriod('late-night snack'), 'late_night');
  assert.equal(mealPeriod('LUNCH'), 'lunch');
  assert.equal(mealPeriod('Snack Window'), undefined);
});

test('withMealPeriod keeps the school name and omits unknown periods', () => {
  assert.deepEqual(withMealPeriod({ name: 'DINNER', stations: [] }), {
    name: 'DINNER', period: 'dinner', stations: [],
  });
  assert.deepEqual(withMealPeriod({ name: 'Continental Breakfast', startTime: '07:30', endTime: '09:00', stations: [] }), {
    name: 'Continental Breakfast', period: 'breakfast', startTime: '07:30', endTime: '09:00', stations: [],
  });
  assert.deepEqual(withMealPeriod({ name: 'Snack Window', stations: [] }), {
    name: 'Snack Window', stations: [],
  });
});
