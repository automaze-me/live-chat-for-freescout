/* Run: node tests/hours.test.js */
'use strict';
var assert = require('assert');
var H = require('../assets/js/lcff-hours.js');

var week = [['09:00', '17:00'], ['09:00', '17:00'], ['09:00', '17:00'], ['09:00', '17:00'], ['09:00', '17:00'], null, null];
var berlin = { tz: 'Europe/Berlin', offset: null, days: week };
function iso(ms) { return new Date(ms).toISOString(); }
function at(s) { return Date.parse(s); }

var r;
// Wed 4 Nov 2026, CET (UTC+1)
r = H.state(berlin, at('2026-11-04T09:00:00Z')); // 10:00 Berlin
assert.strictEqual(r.open, true, 'Wed 10:00 open');
r = H.state(berlin, at('2026-11-04T17:00:00Z')); // 18:00 Berlin
assert.strictEqual(r.open, false, 'Wed 18:00 closed');
assert.strictEqual(iso(r.next), '2026-11-05T08:00:00.000Z', 'next Thu 09:00 Berlin');
r = H.state(berlin, at('2026-11-06T16:00:00Z')); // Fri 17:00 Berlin
assert.strictEqual(r.open, false, 'end is exclusive');
assert.strictEqual(iso(r.next), '2026-11-09T08:00:00.000Z', 'next Mon 09:00');
r = H.state(berlin, at('2026-11-07T11:00:00Z')); // Sat 12:00
assert.strictEqual(r.open, false);
assert.strictEqual(iso(r.next), '2026-11-09T08:00:00.000Z', 'Sat -> Mon');
r = H.state(berlin, at('2026-11-04T07:59:00Z')); // 08:59 Berlin
assert.strictEqual(iso(r.next), '2026-11-04T08:00:00.000Z', 'same-day opening');

// DST: Sunday 29 March 2026 (to CEST), Monday 09:00 = 07:00Z
r = H.state(berlin, at('2026-03-29T10:00:00Z'));
assert.strictEqual(r.open, false);
assert.strictEqual(iso(r.next), '2026-03-30T07:00:00.000Z', 'spring-forward Sunday');
// Sunday 25 October 2026 (to CET), Monday 09:00 = 08:00Z
r = H.state(berlin, at('2026-10-25T10:00:00Z'));
assert.strictEqual(iso(r.next), '2026-10-26T08:00:00.000Z', 'fall-back Sunday');
// Open across the switch week: Friday after spring forward is CEST
r = H.state(berlin, at('2026-04-03T14:00:00Z')); // Fri 16:00 CEST
assert.strictEqual(r.open, true, 'Fri 16:00 CEST open');

// fixed offset +05:30
var fixed = { tz: null, offset: 330, days: week };
// Monday 2026-11-02 08:59 local = 03:29Z
r = H.state(fixed, at('2026-11-02T03:29:00Z'));
assert.strictEqual(r.open, false);
assert.strictEqual(r.next, at('2026-11-02T03:29:00Z') + 60000, 'next one minute later');
r = H.state(fixed, at('2026-11-02T03:30:00Z'));
assert.strictEqual(r.open, true);

// only Tuesday open, Sunday evening Berlin
var tue = { tz: 'Europe/Berlin', offset: null, days: [null, ['10:00', '12:00'], null, null, null, null, null] };
r = H.state(tue, at('2026-11-01T19:00:00Z')); // Sun 20:00
assert.strictEqual(iso(r.next), '2026-11-03T09:00:00.000Z', 'Tuesday 10:00 Berlin');
// Tuesday after closing: next is the following Tuesday (7 days ahead)
r = H.state(tue, at('2026-11-03T12:00:00Z'));
assert.strictEqual(iso(r.next), '2026-11-10T09:00:00.000Z', 'next week');

// nothing open
r = H.state({ tz: 'Europe/Berlin', offset: null, days: [null, null, null, null, null, null, null] }, at('2026-11-04T09:00:00Z'));
assert.deepStrictEqual(r, { open: false, next: null });

// failures cost only the status: state() returns null, never throws
assert.strictEqual(H.state({ tz: 'Bogus/Zone', offset: null, days: week }, at('2026-11-04T09:00:00Z')), null, 'unknown tz');
var orig = Intl.DateTimeFormat.prototype.formatToParts;
var cached = { tz: 'America/Chicago', offset: null, days: week }; // fresh zone, no cached formatter
Intl.DateTimeFormat.prototype.formatToParts = undefined;
try {
	assert.strictEqual(H.state(cached, at('2026-11-04T09:00:00Z')), null, 'no formatToParts');
} finally {
	Intl.DateTimeFormat.prototype.formatToParts = orig;
}
assert.notStrictEqual(H.state(cached, at('2026-11-04T09:00:00Z')), null, 'works again afterwards');
assert.strictEqual(H.state(null, 0).open, false, 'no hours object');

// 24:00 means the end of the day
var late = { tz: 'Europe/Berlin', offset: null, days: [['09:00', '24:00'], ['09:00', '24:00'], ['09:00', '24:00'], ['09:00', '24:00'], ['09:00', '24:00'], ['09:00', '24:00'], ['09:00', '24:00']] };
r = H.state(late, at('2026-11-04T22:59:00Z')); // Wed 23:59 Berlin
assert.strictEqual(r.open, true, '23:59 open with a 24:00 end');
r = H.state(late, at('2026-11-04T23:00:00Z')); // Thu 00:00 Berlin
assert.strictEqual(r.open, false, '00:00 next day closed');
assert.strictEqual(iso(r.next), '2026-11-05T08:00:00.000Z', 'next opening Thu 09:00');

console.log('PASS hours');
