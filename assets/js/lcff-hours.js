/*
 * Live Chat for FreeScout - "open now" from the working-hours schedule.
 * Pure: no DOM, the clock is passed in. Plain ES5.
 *
 * hours = { tz: 'Europe/Berlin' | null, offset: minutes | null, days: [7 x (null | ['HH:MM','HH:MM'])] }
 * days[0] is Monday. The end of a range is exclusive.
 * state(hours, nowMs) -> { open: bool, next: ms of the next opening | null }
 */
(function (root) {
	'use strict';

	var formatters = {};

	function formatter(tz) {
		if (!formatters[tz]) {
			formatters[tz] = new Intl.DateTimeFormat('en-US', {
				timeZone: tz, hourCycle: 'h23', year: 'numeric', month: 'numeric',
				day: 'numeric', hour: 'numeric', minute: 'numeric', second: 'numeric'
			});
		}
		return formatters[tz];
	}

	/* The site's wall clock at an instant, read as if it were UTC. */
	function wallAsUtc(hours, ms) {
		if (hours.tz) {
			var parts = formatter(hours.tz).formatToParts(new Date(ms));
			var v = {};
			for (var i = 0; i < parts.length; i++) {
				if (parts[i].type !== 'literal') { v[parts[i].type] = parseInt(parts[i].value, 10); }
			}
			return Date.UTC(v.year, v.month - 1, v.day, v.hour % 24, v.minute, v.second);
		}
		return ms + (hours.offset || 0) * 60000;
	}

	/* Offset of the site from UTC (ms) at an instant. */
	function offsetAt(hours, ms) {
		return wallAsUtc(hours, ms) - Math.floor(ms / 1000) * 1000;
	}

	/* The instant at which the site's wall clock reads wallUtc (a "wall time as UTC" value). */
	function fromWall(hours, wallUtc) {
		var t = wallUtc - offsetAt(hours, wallUtc);
		return wallUtc - offsetAt(hours, t); // second pass settles a DST day
	}

	function minutes(hhmm) {
		return parseInt(hhmm.slice(0, 2), 10) * 60 + parseInt(hhmm.slice(3, 5), 10);
	}

	function state(hours, nowMs) {
		var days = hours && hours.days;
		if (!days || days.length !== 7) { return { open: false, next: null }; }

		var wall = wallAsUtc(hours, nowMs);
		var base = new Date(wall);
		var midnight = Date.UTC(base.getUTCFullYear(), base.getUTCMonth(), base.getUTCDate());

		for (var i = 0; i < 8; i++) {
			var day = midnight + i * 86400000;
			var range = days[(new Date(day).getUTCDay() + 6) % 7];
			if (!range) { continue; }
			var start = fromWall(hours, day + minutes(range[0]) * 60000);
			var end = fromWall(hours, day + minutes(range[1]) * 60000);
			if (i === 0 && nowMs >= start && nowMs < end) {
				return { open: true, next: null };
			}
			if (start > nowMs) {
				return { open: false, next: start };
			}
		}
		return { open: false, next: null };
	}

	var api = { state: state };
	if (typeof module !== 'undefined' && module.exports) {
		module.exports = api;
	} else {
		root.LCFF_HOURS = api;
	}
})(typeof window !== 'undefined' ? window : this);
