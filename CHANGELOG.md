# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.5] - 2026-06-18

### Fixed
- Store switcher in the editor toolbar only listed "All Store Views". `Editor::getStoreList()`
  read `SystemStore::getStoreValuesForForm()`, whose return value is a nested tree
  (website → group → store views) where the actual store views live inside the parent
  entries' `value` arrays. The old loop kept only items with a numeric top-level `value`,
  so only "All Store Views" (value `0`) survived. The list is now built by recursively
  walking the tree and collecting the numeric store-view leaves (with the indentation
  whitespace stripped from labels).

## [1.0.4] - 2026-05-20

### Fixed
- Email logo rendered with an empty `src` attribute in the preview. The sample-data
  providers (`AdminMockBuilder`, `CustomDataProvider`, `LastCustomerProvider`) passed
  `logo_url => ''`, which satisfies `isset()` in Magento's
  `AbstractTemplate::addEmailVariables()` and suppresses resolution of the configured
  `design/email/logo`. The key is now omitted so Magento resolves the real (or default)
  email logo URL.
