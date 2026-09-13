<template>
	<section ref="grid"
		class="mec-grid"
		:style="{ height: gridHeight }"
		:aria-label="t('Image search results')">
		<ResultTile v-for="(result, position) in results"
			:key="`${result.file_id}-${position}`"
			:result="result"
			:style="tileStyle(position)"
			@ratio="setRatio(position, $event)"
			@similar="$emit('similar', $event)"
			@open="$emit('open', $event)" />
	</section>
</template>

<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ResultTile from './ResultTile.vue';
import { t } from '../l10n.js';

const props = defineProps({
	results: { type: Array, default: () => [] },
});

defineEmits(['similar', 'open']);

/*
 * Rows are justified the way photo galleries lay out mixed aspect ratios: fill
 * each row to the available width, then scale the row height to match. The
 * ratios arrive asynchronously as thumbnails decode, so the layout is
 * recomputed whenever one lands.
 */
const GAP = 4;
const MOBILE_BREAKPOINT = 768;

const grid = ref(null);
const ratios = ref([]);
const positions = ref([]);
const gridHeight = ref('');

let frame = null;
let observer = null;
let lastGridWidth = 0;
let previousGridWidth = 0;
let layoutWidthOverride = 0;

/**
 * Style object that places one tile inside the justified grid.
 *
 * @param {number} position index of the tile
 * @return {object}
 */
function tileStyle(position) {
	const box = positions.value[position];
	if (!box) {
		return { visibility: 'hidden' };
	}
	return {
		transform: `translate(${box.x}px, ${box.y}px)`,
		width: `${box.width}px`,
		height: `${box.height}px`,
	};
}

/**
 * Record a decoded thumbnail's aspect ratio and re-run the layout.
 *
 * @param {number} position index of the tile
 * @param {number} ratio width divided by height
 */
function setRatio(position, ratio) {
	if (ratios.value[position] === ratio) {
		return;
	}
	ratios.value[position] = ratio;
	scheduleLayout();
}

/** Coalesce layout runs into the next animation frame. */
function scheduleLayout() {
	if (frame !== null) {
		return;
	}
	frame = window.requestAnimationFrame(() => {
		frame = null;
		layout();
	});
}

/** Place every tile and set the grid height to the resulting stack. */
function layout() {
	const element = grid.value;
	const measuredWidth = layoutWidthOverride || element?.clientWidth || 0;
	if (!element || !props.results.length || !measuredWidth) {
		positions.value = [];
		gridHeight.value = '';
		return;
	}

	const styles = window.getComputedStyle(element);
	const paddingLeft = parseFloat(styles.paddingLeft) || 0;
	const paddingTop = parseFloat(styles.paddingTop) || 0;
	const paddingBottom = parseFloat(styles.paddingBottom) || 0;
	const availableWidth = measuredWidth - paddingLeft - (parseFloat(styles.paddingRight) || 0);
	const mobile = window.innerWidth <= MOBILE_BREAKPOINT;
	const targetHeight = mobile ? 128 : 190;
	const minHeight = mobile ? 92 : 135;
	const maxHeight = mobile ? 170 : 245;

	const boxes = [];
	let row = [];
	let aspectSum = 0;
	let y = paddingTop;

	const placeRow = (height) => {
		let x = paddingLeft;
		for (const entry of row) {
			const width = height * entry.ratio;
			boxes[entry.position] = {
				x: Math.round(x),
				y: Math.round(y),
				width: Math.round(width),
				height: Math.round(height),
			};
			x += width + GAP;
		}
		y += height + GAP;
	};

	props.results.forEach((result, position) => {
		const ratio = ratios.value[position] ?? 1.5;
		row.push({ position, ratio });
		aspectSum += ratio;
		const gaps = GAP * Math.max(0, row.length - 1);
		if (targetHeight * aspectSum + gaps < availableWidth) {
			return;
		}
		placeRow(Math.max(1, Math.min(maxHeight, (availableWidth - gaps) / aspectSum)));
		row = [];
		aspectSum = 0;
	});

	if (row.length) {
		placeRow(Math.max(minHeight, Math.min(maxHeight, targetHeight)));
	}

	positions.value = boxes;
	gridHeight.value = `${Math.ceil(y - GAP + paddingBottom)}px`;
}

watch(() => props.results, async () => {
	ratios.value = props.results.map((_, position) => ratios.value[position] ?? 1.5);
	await nextTick();
	scheduleLayout();
}, { deep: false });

onMounted(() => {
	if ('ResizeObserver' in window) {
		observer = new ResizeObserver(() => {
			const width = grid.value?.clientWidth ?? 0;
			if (width === lastGridWidth) {
				return;
			}

			/*
			 * A vertical scrollbar can make the grid alternate between two
			 * widths. The changed row breaks then toggle the scrollbar again,
			 * producing an endless resize loop. Detect A-B-A and keep laying
			 * out at the smaller width until a genuine third width appears.
			 */
			if (lastGridWidth && width === previousGridWidth) {
				layoutWidthOverride = Math.min(width, lastGridWidth);
			} else if (layoutWidthOverride) {
				layoutWidthOverride = 0;
			}

			previousGridWidth = lastGridWidth;
			lastGridWidth = width;
			scheduleLayout();
		});
		observer.observe(grid.value);
	} else {
		window.addEventListener('resize', scheduleLayout);
	}
	scheduleLayout();
});

onBeforeUnmount(() => {
	observer?.disconnect();
	window.removeEventListener('resize', scheduleLayout);
	if (frame !== null) {
		window.cancelAnimationFrame(frame);
	}
});
</script>
