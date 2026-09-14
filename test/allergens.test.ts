import assert from 'node:assert/strict';
import test from 'node:test';
import { allergenToken, uniqueSortedAllergens } from '../src/allergens';

test('allergenToken maps vendor labels onto one lowercase token', () => {
  assert.equal(allergenToken('Egg'), 'egg');
  assert.equal(allergenToken('Dairy'), 'milk');
  assert.equal(allergenToken('Soybeans'), 'soy');
  assert.equal(allergenToken('Tree Nut'), 'treenut');
  assert.equal(allergenToken('Tree Nut (Walnut)'), 'treenut');
  assert.equal(allergenToken('Wheat'), 'wheat');
  assert.equal(allergenToken('Gluten'), 'gluten');
  assert.equal(allergenToken('Organic'), undefined);
});

test('uniqueSortedAllergens sorts, dedupes, and omits empty lists', () => {
  assert.deepEqual(uniqueSortedAllergens(['Soy', 'Milk', 'Tree Nut (Almond)', 'Tree Nut (Walnut)', 'Soybeans']), [
    'milk', 'soy', 'treenut',
  ]);
  assert.deepEqual(uniqueSortedAllergens(['Wheat', 'Gluten']), ['gluten', 'wheat']);
  assert.equal(uniqueSortedAllergens(['Organic', 'Local']), undefined);
});
