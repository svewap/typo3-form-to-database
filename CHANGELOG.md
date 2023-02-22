# 2.2.0

⚠️ This release fixes a regression to re-enable the correct TYPO3 support. More details in [!37](https://gitlab.com/lavitto/typo3-form-to-database/-/merge_requests/37)

- [BREAKING] Drop TYPO3 11.5 support
- [BREAKING] Drop TYPO3 8.7 support
- [TASK] Set PHP to 7.4 as a minimum
- [TASK] Save repeatable fields to database (#59)
- [TASK] Improved marking when new entries (!36)
- [TASK] Set CSV to be comma seperated by default (#83)
- [BUGFIX] added quotation marks around identifier numberOfResults because PostgreSQL changes unquoted identifiers to lowercase
- [BUGFIX] Fix undefined index (!30)
- [BUGFIX] Fix undefined array key issues with php 8
- [BUGFIX] Exception in Result List on multipage form
