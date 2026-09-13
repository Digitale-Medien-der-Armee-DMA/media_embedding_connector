import { flushPromises, mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import SearchApp from '../../src/search/SearchApp.vue';
import { post } from '../../src/api.js';

vi.mock('@nextcloud/vue/components/NcActionButton', () => ({ default: { name: 'NcActionButton' } }));
vi.mock('@nextcloud/vue/components/NcAppContent', () => ({ default: { name: 'NcAppContent' } }));
vi.mock('@nextcloud/vue/components/NcAppNavigation', () => ({ default: { name: 'NcAppNavigation' } }));
vi.mock('@nextcloud/vue/components/NcAppNavigationCaption', () => ({ default: { name: 'NcAppNavigationCaption' } }));
vi.mock('@nextcloud/vue/components/NcAppNavigationItem', () => ({ default: { name: 'NcAppNavigationItem' } }));
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton' } }));
vi.mock('@nextcloud/vue/components/NcContent', () => ({ default: { name: 'NcContent' } }));
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => ({ default: { name: 'NcEmptyContent' } }));
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon' } }));
vi.mock('@nextcloud/vue/components/NcTextField', () => ({ default: { name: 'NcTextField' } }));
vi.mock('../../src/search/ResultsGrid.vue', () => ({ default: { name: 'ResultsGrid' } }));

vi.mock('../../src/api.js', () => ({
	post: vi.fn(),
}));

vi.mock('../../src/l10n.js', () => ({
	t: (text, placeholders = {}) => Object.entries(placeholders).reduce(
		(result, [name, value]) => result.replace(`{${name}}`, value),
		text,
	),
}));

const ContentStub = defineComponent({
	template: '<main><slot /></main>',
});

const NavigationStub = defineComponent({
	template: '<nav><slot name="search" /><slot name="list" /></nav>',
});

const ButtonStub = defineComponent({
	props: { disabled: Boolean },
	template: '<button type="button" :disabled="disabled"><slot name="icon" /><slot /></button>',
});

const TextFieldStub = defineComponent({
	inheritAttrs: false,
	props: {
		modelValue: { type: [String, Number], default: '' },
		placeholder: { type: String, default: '' },
	},
	emits: ['update:modelValue'],
	setup(props, { attrs, emit }) {
		return () => h('input', {
			...attrs,
			value: props.modelValue,
			placeholder: props.placeholder,
			onInput: (event) => emit('update:modelValue', event.target.value),
		});
	},
});

const EmptyContentStub = defineComponent({
	props: {
		name: { type: String, default: '' },
		description: { type: String, default: '' },
	},
	template: '<div data-test="empty-content">{{ name }} {{ description }}<slot name="icon" /></div>',
});

const state = {
	indexing_enabled: true,
	image_accept: 'image/jpeg,image/png,image/webp',
	search_url: '/search',
	image_search_url: '/search/image',
	similar_url: '/search/__FILE_ID__',
};

function mountSearch(overrides = {}) {
	return mount(SearchApp, {
		props: { state: { ...state, ...overrides } },
		global: {
			stubs: {
				NcContent: ContentStub,
				NcAppNavigation: NavigationStub,
				NcAppContent: ContentStub,
				NcButton: ButtonStub,
				NcTextField: TextFieldStub,
				NcEmptyContent: EmptyContentStub,
				NcActionButton: ButtonStub,
				NcAppNavigationCaption: ContentStub,
				NcAppNavigationItem: ContentStub,
				NcLoadingIcon: true,
				ResultsGrid: true,
				CloseIcon: true,
				DeleteIcon: true,
				HistoryIcon: true,
				ImagePlusIcon: true,
				ImageSearchOutlineIcon: true,
				MagnifyIcon: true,
				TrayArrowDownIcon: true,
			},
		},
	});
}

function dataTransfer(files) {
	return {
		types: ['Files'],
		files,
		dropEffect: 'none',
	};
}

describe('SearchApp', () => {
	beforeEach(() => {
		window.localStorage.clear();
		window.sessionStorage.clear();
		window.URL.createObjectURL = vi.fn(() => 'blob:test-image');
		window.URL.revokeObjectURL = vi.fn();
		post.mockResolvedValue({
			ok: true,
			payload: { results: [], has_more: false, next_offset: 0 },
		});
	});

	it('renders the search text as a placeholder without the removed permanent notice', () => {
		const wrapper = mountSearch();
		const input = wrapper.get('input[type="search"]');

		expect(input.attributes('placeholder')).toBe('Search images …');
		expect(input.attributes('aria-label')).toBe('Search images');
		expect(wrapper.text()).not.toContain('Supported image content');
		expect(wrapper.text()).not.toContain('Temporary query images');
	});

	it('highlights the search row while an external file is dragged over it', async () => {
		const wrapper = mountSearch();
		const form = wrapper.get('form.mec-search');

		await form.trigger('dragover', { dataTransfer: dataTransfer([]) });
		expect(form.classes()).toContain('mec-search--drop-active');

		await form.trigger('dragleave', { dataTransfer: dataTransfer([]), relatedTarget: null });
		expect(form.classes()).not.toContain('mec-search--drop-active');
	});

	it('starts an image search when one image is dropped on the search row', async () => {
		const wrapper = mountSearch();
		await wrapper.get('input[type="search"]').setValue('Matterhorn');
		const image = new File(['image'], 'query.png', { type: 'image/png' });

		await wrapper.get('form.mec-search').trigger('drop', {
			dataTransfer: dataTransfer([image]),
		});
		await flushPromises();

		expect(post).toHaveBeenCalledTimes(1);
		expect(post.mock.calls[0][0]).toBe('/search/image');
		expect(post.mock.calls[0][1]).toBeInstanceOf(FormData);
		expect(post.mock.calls[0][1].get('image').name).toBe('query.png');
		expect(wrapper.text()).toContain('Similar to query.png');
		expect(wrapper.get('input[type="search"]').element.value).toBe('');
		await wrapper.get('form.mec-search').trigger('submit');
		expect(post).toHaveBeenCalledTimes(1);
		expect(wrapper.get('form.mec-search').classes()).not.toContain('mec-search--drop-active');
	});

	it('clears the text query when searching from an indexed image', async () => {
		const result = { file_id: 42, name: 'flowers.jpg' };
		post.mockResolvedValueOnce({ ok: true, payload: { results: [result], has_more: false } });
		const wrapper = mountSearch();
		await wrapper.get('input[type="search"]').setValue('Matterhorn');
		await wrapper.get('form.mec-search').trigger('submit');
		await flushPromises();
		wrapper.findComponent({ name: 'ResultsGrid' }).vm.$emit('similar', result);
		await flushPromises();
		expect(wrapper.get('input[type="search"]').element.value).toBe('');
		expect(wrapper.text()).toContain('Similar to flowers.jpg');
		expect(post.mock.calls[1][0]).toBe('/search/42');
		await wrapper.get('button[aria-label="Back to text search"]').trigger('click');
		expect(wrapper.get('input[type="search"]').element.value).toBe('');
		expect(post).toHaveBeenCalledTimes(2);
	});

	it('restores an uploaded image after leaving and remounting the search app', async () => {
		const key = 'media_embedding_connector:last-search';
		const wrapper = mountSearch();
		await wrapper.get('form.mec-search').trigger('drop', {
			dataTransfer: dataTransfer([new File(['image bytes'], 'flowers.png', { type: 'image/png' })]),
		});
		await vi.waitFor(() => expect(JSON.parse(sessionStorage.getItem(key))?.mode).toBe('upload'));
		wrapper.unmount();
		post.mockClear();
		const restored = mountSearch();
		await flushPromises();
		expect(restored.text()).toContain('Similar to flowers.png');
		expect(restored.get('input[type="search"]').element.value).toBe('');
		expect(post.mock.calls[0][0]).toBe('/search/image');
		expect(post.mock.calls[0][1].get('image').size).toBe(11);
		await restored.get('button[aria-label="Back to text search"]').trigger('click');
		await new Promise((resolve) => setTimeout(resolve, 30));
		expect(sessionStorage.getItem(key)).toBeNull();
		restored.unmount();
	});

	it('ignores a damaged stored image', () => {
		sessionStorage.setItem('media_embedding_connector:last-search', JSON.stringify({ mode: 'upload', image: 'broken' }));
		const wrapper = mountSearch();
		expect(post).not.toHaveBeenCalled();
		expect(sessionStorage.getItem('media_embedding_connector:last-search')).toBeNull();
		wrapper.unmount();
	});

	it('rejects multiple files dropped on the search row without sending a request', async () => {
		const wrapper = mountSearch();
		const files = [
			new File(['one'], 'one.png', { type: 'image/png' }),
			new File(['two'], 'two.png', { type: 'image/png' }),
		];

		await wrapper.get('form.mec-search').trigger('drop', {
			dataTransfer: dataTransfer(files),
		});

		expect(post).not.toHaveBeenCalled();
		expect(wrapper.get('[data-test="empty-content"]').text())
			.toContain('Drop exactly one image for similarity search.');
	});

	it('rejects a non-image dropped on the search row without sending a request', async () => {
		const wrapper = mountSearch();
		const document = new File(['text'], 'notes.txt', { type: 'text/plain' });

		await wrapper.get('form.mec-search').trigger('drop', {
			dataTransfer: dataTransfer([document]),
		});

		expect(post).not.toHaveBeenCalled();
		expect(wrapper.get('[data-test="empty-content"]').text())
			.toContain('This image format is not supported.');
	});
});
