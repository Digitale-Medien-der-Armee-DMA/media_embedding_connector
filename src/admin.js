import { createApp } from 'vue';
import { loadState } from '@nextcloud/initial-state';
import AdminSettings from './admin/AdminSettings.vue';
import './admin/admin.css';
import { APP_ID } from './l10n.js';

createApp(AdminSettings, { state: loadState(APP_ID, 'admin') })
	.mount('#media-embedding-connector-admin');
