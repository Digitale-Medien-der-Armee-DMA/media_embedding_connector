# Third-party notices

Unless stated below, the distributed application code is licensed under
`AGPL-3.0-or-later` as described in `LICENSE`.

## Application icon

The files `img/app.svg` and `img/app-dark.svg` are adapted from Nextcloud's
`core/img/categories/multimedia.svg` Material icon. They were resized and
recoloured for this application.

- Copyright: 2018-2024 Google LLC
- License: Apache License 2.0 (`Apache-2.0`)
- Upstream: <https://github.com/nextcloud/server/blob/028965684401bd9deca3ffad02a11b566c5878a7/core/img/categories/multimedia.svg>
- License text: `LICENSES/Apache-2.0.txt`

## Search interface icons

The compiled admin and search bundles include selected Vue components from
`vue-material-design-icons`. These components render SVG paths from the
Pictogrammers Material Design Icons collection.

- Components: `BackupRestore`, `Close`, `ContentSave`, `DatabaseCheck`,
  `DatabaseOutline`, `DatabasePlus`, `DatabaseSearch`, `Delete`,
  `FileDocumentOutline`, `FolderOutline`, `FormatText`, `History`, `Image`,
  `ImageMultiple`, `ImagePlus`, `ImageSearchOutline`, `InformationOutline`,
  `LanConnect`, `Magnify`, `Pause`, `Play`, `Refresh`, and `TrayArrowDown`
- Distributed in: `js/admin.js` and `js/search.js`
- Copyright: Material Design Icons contributors (Pictogrammers); the collection
  also includes icons converted from Google's Material Design icon set
- Icon license: Apache License 2.0 (`Apache-2.0`)
- Vue component wrapper: `vue-material-design-icons`, MIT
- Upstream collection: <https://github.com/Templarian/MaterialDesign>
- Upstream license information: <https://pictogrammers.com/docs/general/license/>
- License text: `LICENSES/Apache-2.0.txt`

## Runtime dependencies

The compiled frontend bundles contain code from the direct runtime dependencies
declared in `package.json`: Nextcloud Auth, Initial State, L10n and Vue
components; Vue; and `vue-material-design-icons`. Their declared licenses are
`GPL-3.0-or-later`, `AGPL-3.0-or-later`, or MIT. Exact versions, transitive
packages, and declared licenses are recorded in `package-lock.json` and checked
by `npm run licenses:check`.

Composer packages and npm development dependencies are used for development,
testing, static analysis, and frontend builds. The generated release archive
contains neither `vendor/` nor `node_modules/`.

Any future npm runtime dependency must be added to the explicit approval list in
`scripts/check-license-policy.mjs`, pass `npm run licenses:check`, and be recorded
here before release.
