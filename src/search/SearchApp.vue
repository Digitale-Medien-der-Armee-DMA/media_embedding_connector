<template>
	<NcContent app-name="media_embedding_connector">
		<NcAppNavigation :aria-label="t('Search images')">
			<template #search>
				<form class="mec-search"
					:class="{ 'mec-search--drop-active': searchDropActive }"
					role="search"
					@submit.prevent="runTextSearch(query)"
					@dragover="onSearchDragOver"
					@dragleave="onSearchDragLeave"
					@drop="onSearchDrop">
					<NcButton class="mec-search__upload"
						variant="tertiary"
						:disabled="!indexingEnabled"
						:aria-label="t('Select an image for similarity search')"
						:title="t('Select an image for similarity search')"
						@click="pickImage">
						<template #icon>
							<ImagePlusIcon :size="20" />
						</template>
					</NcButton>
					<input ref="imageInput"
						type="file"
						class="mec-search__file"
						:accept="imageAccept"
						:disabled="!indexingEnabled"
						@change="onImagePicked">
					<NcTextField v-model="query"
						class="mec-search__field"
						type="search"
						maxlength="2000"
						:aria-label="t('Search images')"
						:placeholder="t('Search images …')"
						:show-trailing-button="false">
						<template #icon>
							<MagnifyIcon :size="20" />
						</template>
					</NcTextField>
				</form>
			</template>

			<template #list>
				<div v-if="context" class="mec-context">
					<img v-if="context.previewUrl"
						class="mec-context__preview"
						:src="context.previewUrl"
						alt="">
					<ImageSearchOutlineIcon v-else :size="20" />
					<span class="mec-context__label">{{ context.label }}</span>
					<NcButton variant="tertiary"
						:aria-label="t('Back to text search')"
						:title="t('Back to text search')"
						@click="resetContext">
						<template #icon>
							<CloseIcon :size="16" />
						</template>
					</NcButton>
				</div>

				<template v-if="history.length">
					<NcAppNavigationCaption :name="t('Recent searches')">
						<template #actions>
							<NcActionButton :aria-label="t('Clear')" @click="clearHistory">
								<template #icon>
									<DeleteIcon :size="20" />
								</template>
								{{ t('Clear') }}
							</NcActionButton>
						</template>
					</NcAppNavigationCaption>
					<NcAppNavigationItem v-for="entry in history"
						:key="entry"
						:name="entry"
						@click="runTextSearch(entry)">
						<template #icon>
							<HistoryIcon :size="20" />
						</template>
					</NcAppNavigationItem>
				</template>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<div ref="content"
				class="mec-content"
				@dragover="onDragOver"
				@dragleave="onDragLeave"
				@drop="onDrop">
				<div v-if="dropActive" class="mec-drop">
					<TrayArrowDownIcon :size="48" />
					<h3>{{ t('Drop an image here') }}</h3>
				</div>

				<NcEmptyContent v-if="emptyState" class="mec-search-empty"
					:name="emptyState.name"
					:description="emptyState.description">
					<template #icon>
						<NcLoadingIcon v-if="loading && !results.length" :size="20" />
						<ImageSearchOutlineIcon v-else :size="20" />
					</template>
				</NcEmptyContent>

				<ResultsGrid v-if="results.length"
					:results="results"
					@similar="runSimilarSearch"
					@open="openResult" />

				<div ref="sentinel" class="mec-sentinel" aria-hidden="true" />

				<div v-if="hasMore && !autoScroll" class="mec-more">
					<NcButton :disabled="loading" @click="loadMore">
						{{ t('Load more') }}
					</NcButton>
				</div>
			</div>
		</NcAppContent>
	</NcContent>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import NcActionButton from '@nextcloud/vue/components/NcActionButton';
import NcAppContent from '@nextcloud/vue/components/NcAppContent';
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation';
import NcAppNavigationCaption from '@nextcloud/vue/components/NcAppNavigationCaption';
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem';
import NcButton from '@nextcloud/vue/components/NcButton';
import NcContent from '@nextcloud/vue/components/NcContent';
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent';
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon';
import NcTextField from '@nextcloud/vue/components/NcTextField';
import CloseIcon from 'vue-material-design-icons/Close.vue';
import DeleteIcon from 'vue-material-design-icons/Delete.vue';
import HistoryIcon from 'vue-material-design-icons/History.vue';
import ImagePlusIcon from 'vue-material-design-icons/ImagePlus.vue';
import ImageSearchOutlineIcon from 'vue-material-design-icons/ImageSearchOutline.vue';
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue';
import TrayArrowDownIcon from 'vue-material-design-icons/TrayArrowDown.vue';
import ResultsGrid from './ResultsGrid.vue';
import { post } from '../api.js';
import { t } from '../l10n.js';

const props = defineProps({ state: { type: Object, required: true } });

const HISTORY_KEY = 'media_embedding_connector:history';
const SESSION_KEY = 'media_embedding_connector:last-search';
const HISTORY_LIMIT = 10;
const PAGE_SIZE = 48;
// Base64 expands the file by one third; leave room in sessionStorage for Nextcloud.
const SESSION_IMAGE_LIMIT = 3 * 1024 * 1024;

const indexingEnabled = Boolean(props.state.indexing_enabled);
const imageAccept = props.state.image_accept ?? 'image/*';

const query = ref('');
const results = ref([]);
const history = ref(readHistory());
const context = ref(null);
const loading = ref(false);
const hasMore = ref(false);
const dropActive = ref(false);
const searchDropActive = ref(false);
const errorMessage = ref('');

const content = ref(null);
const sentinel = ref(null);
const imageInput = ref(null);

/* The sentinel drives paging; the button is the fallback without observers. */
const autoScroll = 'IntersectionObserver' in window;

let current = { mode: 'text', query: '', fileId: '', name: '', file: null, offset: 0 };
let requestSequence = 0;
let dropTimer = null;
let observer = null;
let previewUrl = '';

const emptyState = computed(() => {
	if (results.value.length) {
		return null;
	}
	if (errorMessage.value) {
		return { name: errorMessage.value, description: '' };
	}
	if (loading.value) {
		return { name: t('Searching…'), description: '' };
	}
	if (!indexingEnabled) {
		return { name: t('Image indexing is not enabled yet.'), description: '' };
	}
	return { name: t('Enter a description to search indexed images.'), description: '' };
});

/**
 * Read a JSON value from web storage, tolerating private mode and quotas.
 *
 * @param {Storage} storage storage to read from
 * @param {string} key storage key
 * @param {*} fallback value used when nothing valid is stored
 * @return {*}
 */
function readStorage(storage, key, fallback) {
	try {
		const raw = storage.getItem(key);
		return raw ? JSON.parse(raw) : fallback;
	} catch (error) {
		return fallback;
	}
}

/**
 * Write a JSON value to web storage, ignoring storage failures.
 *
 * @param {Storage} storage storage to write to
 * @param {string} key storage key
 * @param {*} value value to store
 */
function writeStorage(storage, key, value) {
	try {
		storage.setItem(key, JSON.stringify(value));
	} catch (error) {
		/* Storage may be unavailable in private mode or over quota. */
	}
}

/**
 * Load the remembered text queries.
 *
 * @return {string[]}
 */
function readHistory() {
	const items = readStorage(window.localStorage, HISTORY_KEY, []);
	return Array.isArray(items) ? items.filter((entry) => typeof entry === 'string') : [];
}

/**
 * Remember a text query at the top of the history.
 *
 * @param {string} value query to remember
 */
function rememberQuery(value) {
	if (!value) {
		return;
	}
	history.value = [value, ...history.value.filter((entry) => entry !== value)].slice(0, HISTORY_LIMIT);
	writeStorage(window.localStorage, HISTORY_KEY, history.value);
}

/** Forget every remembered query. */
function clearHistory() {
	history.value = [];
	try {
		window.localStorage.removeItem(HISTORY_KEY);
	} catch (error) {
		/* Nothing to clean up when storage is unavailable. */
	}
}

/** Persist the active search so a reload restores it. */
function persistCurrent() {
	if (current.mode === 'upload') {
		try {
			window.sessionStorage.removeItem(SESSION_KEY);
		} catch (error) {
			/* Nothing to clean up when storage is unavailable. */
		}
		const search = current;
		if (!search.file || search.file.size > SESSION_IMAGE_LIMIT) {
			return;
		}
		const reader = new FileReader();
		reader.onload = () => {
			// A slower file read must not overwrite a newer search or a reset.
			if (current !== search || typeof reader.result !== 'string') {
				return;
			}
			writeStorage(window.sessionStorage, SESSION_KEY, {
				mode: 'upload', name: search.name, type: search.file.type,
				image: reader.result,
			});
		};
		reader.readAsDataURL(search.file);
		return;
	}
	writeStorage(window.sessionStorage, SESSION_KEY, {
		mode: current.mode,
		query: current.query,
		fileId: current.fileId,
		name: current.name,
	});
}

/** Release the object URL of an uploaded query image. */
function releasePreview() {
	if (previewUrl) {
		window.URL.revokeObjectURL(previewUrl);
		previewUrl = '';
	}
}

/**
 * Map an image-search error code to an actionable message.
 *
 * @param {string} code error code returned by the controller
 * @return {string}
 */
function imageSearchError(code) {
	const messages = {
		missing_image: t('Select an image for similarity search'),
		invalid_image_upload: t('The image could not be read.'),
		unsupported_image_type: t('This image format is not supported.'),
		image_too_large: t('The image is too large.'),
		image_pixel_limit_exceeded: t('The image dimensions are too large.'),
	};
	return messages[code] ?? t('Image search could not be completed.');
}

/**
 * Fetch one page of results for the active search.
 *
 * @param {boolean} append whether to keep the results already shown
 */
async function load(append) {
	if (append && loading.value) {
		return;
	}
	const requestId = ++requestSequence;
	const mode = current.mode;
	loading.value = true;
	if (!append) {
		errorMessage.value = '';
		results.value = [];
	}

	const offset = append ? String(current.offset) : '0';
	let url = props.state.search_url;
	let body;

	if (mode === 'similar') {
		url = props.state.similar_url.replace('__FILE_ID__', encodeURIComponent(current.fileId));
		body = new URLSearchParams({ limit: String(PAGE_SIZE), offset });
	} else if (mode === 'upload') {
		url = props.state.image_search_url;
		body = new FormData();
		body.append('image', current.file, current.name);
		body.append('limit', String(PAGE_SIZE));
		body.append('offset', offset);
	} else {
		body = new URLSearchParams({ limit: String(PAGE_SIZE), offset, query: current.query });
	}

	try {
		const { ok, payload } = await post(url, body);
		if (requestId !== requestSequence) {
			return;
		}
		if (!ok) {
			throw new Error(payload.error || 'search_failed');
		}
		results.value = append ? [...results.value, ...payload.results] : payload.results;
		current.offset = payload.next_offset ?? 0;
		hasMore.value = Boolean(payload.has_more);
		if (!append && payload.results.length === 0) {
			errorMessage.value = t('No accessible images matched this search.');
		}
	} catch (error) {
		if (requestId !== requestSequence) {
			return;
		}
		if (!append) {
			errorMessage.value = mode === 'upload'
				? imageSearchError(error.message)
				: t('Search could not be completed.');
		}
	} finally {
		if (requestId === requestSequence) {
			loading.value = false;
		}
	}
}

/** Load the next page of the active search. */
function loadMore() {
	if (hasMore.value && !loading.value) {
		load(true);
	}
}

/** Scroll the result area back to the top for a new search. */
function scrollToTop() {
	if (content.value) {
		content.value.scrollTop = 0;
	}
}

/**
 * Run a text search.
 *
 * @param {string} value query text
 * @param {object} [options] behaviour flags
 * @param {boolean} [options.remember] whether to add the query to the history
 */
function runTextSearch(value, options = {}) {
	const trimmed = (value ?? '').trim();
	if (trimmed === '') {
		return;
	}
	releasePreview();
	query.value = trimmed;
	context.value = null;
	current = { mode: 'text', query: trimmed, fileId: '', name: '', file: null, offset: 0 };
	if (options.remember !== false) {
		rememberQuery(trimmed);
	}
	persistCurrent();
	scrollToTop();
	load(false);
}

/**
 * Search for images similar to an already indexed file.
 *
 * @param {object} result result whose vector is used as the query
 */
function runSimilarSearch(result) {
	releasePreview();
	query.value = '';
	current = {
		mode: 'similar',
		query: '',
		fileId: result.file_id,
		name: result.name,
		file: null,
		offset: 0,
	};
	context.value = { label: t('Similar to {name}', { name: result.name }), previewUrl: '' };
	persistCurrent();
	scrollToTop();
	load(false);
}

/**
 * Search for images similar to a locally selected image.
 *
 * @param {File} file image chosen or dropped by the user
 */
function runUploadedImageSearch(file) {
	if (!file) {
		return;
	}
	if (file.type && !file.type.startsWith('image/')) {
		errorMessage.value = imageSearchError('unsupported_image_type');
		results.value = [];
		return;
	}

	releasePreview();
	previewUrl = window.URL.createObjectURL(file);
	query.value = '';
	const name = file.name || t('Uploaded image');
	current = { mode: 'upload', query: '', fileId: '', name, file, offset: 0 };
	context.value = { label: t('Similar to {name}', { name }), previewUrl };
	persistCurrent();
	scrollToTop();
	load(false);
}

/** Leave the similarity context and return to text search. */
function resetContext() {
	releasePreview();
	context.value = null;
	const trimmed = query.value.trim();
	if (trimmed !== '') {
		runTextSearch(trimmed);
		return;
	}
	current = { mode: 'text', query: '', fileId: '', name: '', file: null, offset: 0 };
	results.value = [];
	hasMore.value = false;
	errorMessage.value = '';
	try {
		window.sessionStorage.removeItem(SESSION_KEY);
	} catch (error) {
		/* Nothing to clean up when storage is unavailable. */
	}
}

/** Open the hidden file input. */
function pickImage() {
	imageInput.value?.click();
}

/** Start an uploaded-image search for the picked file. */
function onImagePicked() {
	const file = imageInput.value?.files?.[0];
	if (imageInput.value) {
		imageInput.value.value = '';
	}
	runUploadedImageSearch(file);
}

/**
 * Describe a result the way the Viewer expects it.
 *
 * @param {object} result search result
 * @return {object}
 */
function viewerFileInfo(result) {
	const path = (result.path || `/${result.name}`).replace(/^\/?/, '/');
	return {
		filename: path,
		basename: result.name,
		displayname: result.name,
		mime: result.mime_type,
		etag: result.etag || String(result.mtime ?? ''),
		fileid: Number(result.file_id),
		size: Number(result.size ?? 0),
		hasPreview: result.has_preview !== false,
		permissions: 'R',
	};
}

/**
 * Open a result in the Viewer, falling back to the Files link.
 *
 * @param {object} payload tile click payload
 * @param {object} payload.result clicked result
 * @param {MouseEvent} payload.event originating click
 */
function openResult({ result, event }) {
	if (typeof window.OCA?.Viewer?.open !== 'function') {
		return;
	}
	event.preventDefault();
	window.OCA.Viewer.open({
		fileInfo: viewerFileInfo(result),
		list: results.value.map(viewerFileInfo),
		enableSidebar: false,
		canLoop: false,
	});
}

/**
 * Whether a drag event carries files from outside the browser.
 *
 * @param {DragEvent} event drag event
 * @return {boolean}
 */
function hasExternalFiles(event) {
	return Array.from(event.dataTransfer?.types ?? []).includes('Files');
}

/**
 * Highlight the search control when an external image is dragged over it.
 *
 * @param {DragEvent} event drag event
 */
function onSearchDragOver(event) {
	if (!indexingEnabled || !hasExternalFiles(event)) {
		return;
	}
	event.preventDefault();
	event.stopPropagation();
	event.dataTransfer.dropEffect = 'copy';
	searchDropActive.value = true;
}

/**
 * Remove the search-control highlight after leaving the complete form.
 *
 * @param {DragEvent} event drag event
 */
function onSearchDragLeave(event) {
	if (event.relatedTarget && event.currentTarget?.contains(event.relatedTarget)) {
		return;
	}
	searchDropActive.value = false;
}

/**
 * Run the normal uploaded-image flow for a file dropped on the search control.
 *
 * @param {DragEvent} event drop event
 */
function onSearchDrop(event) {
	searchDropActive.value = false;
	onDrop(event);
}

/**
 * Show the drop hint while an image is dragged over the result area.
 *
 * @param {DragEvent} event drag event
 */
function onDragOver(event) {
	if (!indexingEnabled || !hasExternalFiles(event)) {
		return;
	}
	event.preventDefault();
	event.dataTransfer.dropEffect = 'copy';
	dropActive.value = true;
	window.clearTimeout(dropTimer);
	dropTimer = window.setTimeout(() => {
		dropActive.value = false;
	}, 3000);
}

/**
 * Hide the drop hint once the pointer leaves the result area.
 *
 * @param {DragEvent} event drag event
 */
function onDragLeave(event) {
	if (event.relatedTarget && content.value?.contains(event.relatedTarget)) {
		return;
	}
	dropActive.value = false;
	window.clearTimeout(dropTimer);
}

/**
 * Start a similarity search for a dropped image.
 *
 * @param {DragEvent} event drop event
 */
function onDrop(event) {
	if (!indexingEnabled || !hasExternalFiles(event)) {
		return;
	}
	event.preventDefault();
	event.stopPropagation();
	dropActive.value = false;
	searchDropActive.value = false;
	window.clearTimeout(dropTimer);

	const files = Array.from(event.dataTransfer.files ?? []);
	if (files.length !== 1) {
		errorMessage.value = t('Drop exactly one image for similarity search.');
		results.value = [];
		return;
	}
	runUploadedImageSearch(files[0]);
}

onMounted(() => {
	if (autoScroll && sentinel.value) {
		observer = new IntersectionObserver((entries) => {
			for (const entry of entries) {
				if (entry.isIntersecting) {
					loadMore();
				}
			}
		}, { root: content.value, rootMargin: '400px' });
		observer.observe(sentinel.value);
	}

	if (!indexingEnabled) {
		return;
	}

	const last = readStorage(window.sessionStorage, SESSION_KEY, null);
	if (last?.mode === 'upload' && typeof last.image === 'string') {
		try {
			if (last.image.length > SESSION_IMAGE_LIMIT * 4 / 3 + 1024) {
				throw new Error('session_image_too_large');
			}
			const encoded = last.image.match(/^data:[^,]*;base64,(.*)$/);
			if (!encoded) {
				throw new Error('invalid_session_image');
			}
			const bytes = Uint8Array.from(atob(encoded[1]), (character) => character.charCodeAt(0));
			runUploadedImageSearch(new File([bytes], last.name || 'image', { type: last.type || '' }));
		} catch (error) {
			try {
				window.sessionStorage.removeItem(SESSION_KEY);
			} catch (_) { /* Storage may be unavailable. */ }
		}
	} else if (last?.mode === 'similar' && last.fileId) {
		runSimilarSearch({ file_id: last.fileId, name: last.name ?? '' });
	} else if (last?.mode === 'text' && last.query) {
		query.value = last.query;
		runTextSearch(last.query, { remember: false });
	}
});

onBeforeUnmount(() => {
	observer?.disconnect();
	window.clearTimeout(dropTimer);
	releasePreview();
});
</script>
