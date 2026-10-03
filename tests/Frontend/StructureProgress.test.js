import { mount } from '@vue/test-utils';
import { defineComponent } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import StructureProgress from '../../src/admin/StructureProgress.vue';

vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: defineComponent({
		props: { disabled: Boolean },
		template: '<button :disabled="disabled"><slot /></button>',
	}),
}));
vi.mock('../../src/l10n.js', () => ({
	t: (text, values = {}) => Object.entries(values).reduce((result, [key, value]) => result.replace(`{${key}}`, value), text),
}));

function render(migration) {
	return mount(StructureProgress, { props: { migration } });
}

describe('StructureProgress', () => {
	it('shows the three lines and emits all four admin actions', async () => {
		const wrapper = render({ status: 'running', processed: 742000, total: 1000000 });
		expect(wrapper.findAll('p').map((p) => p.text())).toEqual([
			'Update search index · Schema 1 → 2',
			'Status: Adding structure metadata',
			`Processed: ${(742000).toLocaleString()} / ${(1000000).toLocaleString()}`,
		]);
		const buttons = wrapper.findAll('button');
		await buttons[0].trigger('click');
		await buttons[1].trigger('click');
		await buttons[2].trigger('click');
		expect(wrapper.emitted('download-errors')).toHaveLength(1);
		expect(wrapper.emitted('control')).toEqual([['restart'], ['pause']]);
		await wrapper.setProps({ migration: { status: 'running', paused: true } });
		await buttons[3].trigger('click');
		expect(wrapper.emitted('control')[2]).toEqual(['resume']);
	});

	it('reports paused, failed and pending repairs without calling a finished run complete', () => {
		expect(render({ status: 'running', paused: true }).text()).toContain('Status: Paused');
		expect(render({ status: 'failed' }).text()).toContain('Status: Update failed');
		expect(render({ status: 'completed', repair_pending: true }).text()).toContain('Status: Adding structure metadata');
		const failed = render({ status: 'completed', has_errors: true });
		expect(failed.text()).toContain('Status: Update failed');
		expect(failed.findAll('button')[3].attributes('disabled')).toBeUndefined();
	});
});
