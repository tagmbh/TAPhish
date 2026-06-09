/**
 * Node-based unit tests for spear/js/wizard_pure.js — the pure, DOM-free
 * wizard helpers. Zero dependencies; run with:  node tests/js/wizardPure.test.mjs
 *
 * Covers wireBody (idempotency-critical: it must not double-wire the CTA or
 * the {{TRACKER}} pixel on re-save) and slugifyName.
 */
import { createRequire } from 'node:module';
import assert from 'node:assert/strict';

const require = createRequire(import.meta.url);
const { wireBody, slugifyName } = require('../../spear/js/wizard_pure.js');

const LANDING = 'https://phish.example/p/m365/';
let passed = 0;
function test(name, fn) { fn(); passed++; }

// --- wireBody --------------------------------------------------------------

test('replaces the REPLACE-WITH-LANDING-URL marker with the CTA href', () => {
    const out = wireBody('<a href="https://example.com/REPLACE-WITH-LANDING-URL">Go</a>', LANDING);
    assert.ok(!/REPLACE-WITH-LANDING-URL/.test(out), 'marker must be gone');
    assert.ok(out.includes(LANDING + '?rid={{RID}}'), 'CTA href must carry rid token');
});

test('replaces a bare marker (no example.com prefix) too', () => {
    const out = wireBody('Click REPLACE-WITH-LANDING-URL now', LANDING);
    assert.ok(out.includes(LANDING + '?rid={{RID}}'));
});

test('appends a CTA when the landing URL is absent', () => {
    const out = wireBody('<p>Hello {{FNAME}}</p>', LANDING);
    assert.ok(out.includes('<a href="' + LANDING + '?rid={{RID}}">'));
});

test('appends the {{TRACKER}} pixel when absent', () => {
    const out = wireBody('<p>Hi</p>', LANDING);
    assert.ok(out.endsWith('{{TRACKER}}'));
});

test('is idempotent — re-wiring its own output changes nothing', () => {
    const once = wireBody('<p>Hi {{FNAME}}</p>', LANDING);
    const twice = wireBody(once, LANDING);
    assert.equal(twice, once, 'second pass must be a no-op');
    // exactly one CTA anchor and one pixel
    assert.equal((twice.match(/<a href=/g) || []).length, 1, 'one CTA anchor only');
    assert.equal((twice.match(/\{\{TRACKER\}\}/g) || []).length, 1, 'one pixel only');
});

test('uses & to join rid when the landing URL already has a query', () => {
    const out = wireBody('<p>x</p>', 'https://phish.example/p/m365/?a=1');
    assert.ok(out.includes('https://phish.example/p/m365/?a=1&rid={{RID}}'));
});

test('with no landing URL still injects the pixel but no CTA', () => {
    const out = wireBody('<p>x</p>', '');
    assert.ok(out.endsWith('{{TRACKER}}'));
    assert.ok(!out.includes('rid={{RID}}'));
});

test('handles null/undefined html safely', () => {
    assert.equal(wireBody(null, ''), '{{TRACKER}}');
});

// --- slugifyName -----------------------------------------------------------

test('slugify lowercases and dashes non-alnum', () => {
    assert.equal(slugifyName('Acme M365 Login!'), 'acme-m365-login');
});

test('slugify trims leading/trailing dashes and caps length', () => {
    assert.equal(slugifyName('  --Hi--  '), 'hi');
    assert.ok(slugifyName('a'.repeat(80)).length <= 48);
});

console.log(`wizardPure: ${passed} tests passed`);
