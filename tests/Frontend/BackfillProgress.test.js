import { mount } from '@vue/test-utils';
import { defineComponent } from 'vue';
import { describe, expect, it, vi } from 'vitest';

import BackfillProgress from '../../src/admin/BackfillProgress.vue';

vi.mock('@nextcloud/vue/components/NcProgressBar', () => ({
	default: defineComponent({
		name: 'NcProgressBar',
		props: { value: Number },
		template: '<div class="progress" :data-value="value" />',
	}),
}));
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({
	default: defineComponent({
		name: 'NcNoteCard',
		props: { heading: String },
		template: '<div class="note">{{ heading }}<slot /></div>',
	}),
}));
vi.mock('../../src/l10n.js', () => ({
	t: (text, placeholders = {}) => Object.entries(placeholders).reduce(
		(result, [name, value]) => result.replace(`{${name}}`, value),
		text,
	),
}));

const NOW = 1_800_000_000;

function running(overrides = {}) {
	return {
		status: 'running',
		paused: false,
		updated_at: NOW - 60,
		throttled_at: null,
		queued_backfill: 120,
		max_queued: 10000,
		user_offset: 3,
		users_total: 12,
		counters: { scanned: 5000, queued: 400, unchanged: 4600, roots_skipped: 0 },
		last_error: null,
		...overrides,
	};
}

function render(backfill) {
	return mount(BackfillProgress, { props: { backfill, now: NOW } });
}

describe('BackfillProgress', () => {
	it('shows scanning state, user progress, and counters', () => {
		const wrapper = render(running());

		expect(wrapper.find('.mec-badge').text()).toBe('Scanning');
		expect(wrapper.find('.progress').attributes('data-value')).toBe('25');
		expect(wrapper.text()).toContain('3 of 12 users scanned');
		expect(wrapper.text()).toContain((4600).toLocaleString());
		expect(wrapper.text()).toContain(`120 / ${(10000).toLocaleString()}`);
		expect(wrapper.find('.note').exists()).toBe(false);
	});

	it('distinguishes paused, throttled, completed, and idle scans', () => {
		expect(render(running({ paused: true })).find('.mec-badge').text()).toBe('Paused');
		expect(render(running({ throttled_at: NOW })).find('.mec-badge').text()).toBe('Waiting for the queue');
		expect(render({ status: 'idle' }).find('.mec-badge').text()).toBe('Not started');

		const completed = render(running({ status: 'completed', completed_at: NOW, user_offset: 12 }));
		expect(completed.find('.mec-badge').text()).toBe('Completed');
		expect(completed.find('.progress').attributes('data-value')).toBe('100');
	});

	it('warns when a running scan has made no progress for 15 minutes', () => {
		expect(render(running({ updated_at: NOW - 16 * 60 })).text()).toContain('No scan progress');
		expect(render(running({ updated_at: NOW - 16 * 60, paused: true })).text()).not.toContain('No scan progress');
	});

	it('shows the last scan error and skipped locations', () => {
		const wrapper = render(running({
			last_error: { at: NOW, exception_class: 'RuntimeException', message: 'storage unavailable' },
			counters: { scanned: 1, queued: 0, unchanged: 0, roots_skipped: 2 },
		}));

		expect(wrapper.text()).toContain('Last scan error');
		expect(wrapper.text()).toContain('RuntimeException: storage unavailable');
		expect(wrapper.text()).toContain('2 scan locations were skipped after repeated failures.');
	});
});
