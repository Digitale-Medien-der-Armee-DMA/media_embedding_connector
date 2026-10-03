import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import StatusSection from '../../src/admin/StatusSection.vue';

vi.mock('../../src/l10n.js', () => ({ t: (text) => text }));
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { template: '<button><slot /></button>' } }));
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => ({ default: { template: '<div />' } }));
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { template: '<div />' } }));
vi.mock('@nextcloud/vue/components/NcSettingsSection', () => ({ default: { template: '<section><slot /></section>' } }));

function render() {
	return mount(StatusSection, {
		props: { status: { jobs: { queued: 12, running: 3, failed: 5 }, skip_markers: { total: 23000 } } },
		global: { stubs: { NcSettingsSection: { template: '<section><slot /></section>' }, NcButton: true, NcEmptyContent: true } },
	});
}

describe('operational status downloads', () => {
	it('offers the correct export for all four statuses, including an empty queue', async () => {
		const wrapper = render();
		const buttons = wrapper.findAll('.mec-metric--download');
		expect(buttons).toHaveLength(4);
		for (const button of buttons) {
			expect(button.attributes('type')).toBe('button');
			expect(button.text()).toContain('Download file list (JSON)');
			await button.trigger('click');
		}
		expect(wrapper.emitted('download-status')).toEqual([['queued'], ['running'], ['failed'], ['skipped']]);
		await wrapper.setProps({ status: {} });
		expect(wrapper.findAll('.mec-metric--download')).toHaveLength(4);
	});
});
