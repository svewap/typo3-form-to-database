# TYPO3 Extension `Form to Database`

[![Donate](https://img.shields.io/badge/Donate-PayPal-green.svg)](https://www.paypal.me/lavittoag/10)
[![Latest Stable Version](https://poser.pugx.org/lavitto/typo3-form-to-database/v/stable)](https://packagist.org/packages/lavitto/typo3-form-to-database)
[![Total Downloads](https://poser.pugx.org/lavitto/typo3-form-to-database/downloads)](https://packagist.org/packages/lavitto/typo3-form-to-database)
[![License](https://poser.pugx.org/lavitto/typo3-form-to-database/license)](https://packagist.org/packages/lavitto/typo3-form-to-database)

> This extension adds an additional finisher to the TYPO3 Form (tx_form) to save the results into the database

- **Gitlab Repository**: [gitlab.com/lavitto/typo3-form-to-database](https://gitlab.com/lavitto/typo3-form-to-database)
- **TYPO3 Extension Repository**: [extensions.typo3.org/extension/form_to_database](https://extensions.typo3.org/extension/form_to_database)
- **Found an issue?**: [gitlab.com/lavitto/typo3-form-to-database/issues](https://gitlab.com/lavitto/typo3-form-to-database/issues)

## 1. Introduction

### Features

- Very simple installation
- No configuration needed
- No database-changes per form required
- Shows all results per form in a separate backend module
- Provides a CSV-download of all results
- Automatic deletion of results after a specified number of days (GDPR)

### Screenshots

#### Backend Overview

![Backend Overview](https://cdn.lavitto.ch/typo3/lavitto/typo3-form-to-database/typo3-form-to-database-backend-overview_tmb.png)
- [Full Size Screenshot](https://cdn.lavitto.ch/typo3/lavitto/typo3-form-to-database/typo3-form-to-database-backend-overview.png)

#### Backend Results

![Backend Results](https://cdn.lavitto.ch/typo3/lavitto/typo3-form-to-database/typo3-form-to-database-backend-results_tmb.png)
- [Full Size Screenshot](https://cdn.lavitto.ch/typo3/lavitto/typo3-form-to-database/typo3-form-to-database-backend-results.png)

## 2. Installation

### Installation using Composer

The recommended way to install the extension is by using [Composer](https://getcomposer.org/). In your Composer based 
TYPO3 project root, just do `composer req lavitto/typo3-form-to-database`.

### Installation from TYPO3 Extension Repository (TER)

Download and install the extension `form_to_database` with the extension manager module.

## 3. Minimal setup

No setup required.

## 4. Administration

### Simple add the finisher to your form

1) Create a new or edit an existing form
2) Add the finisher "Save the mail to the Database"
3) Save the form

## 5. Command / Scheduler

It's possible to delete the form results by the command line or scheduler (Execute console commands).

```shell script
Usage:
  form_to_database:deleteFormResults [<maxAge>]

Arguments:
  maxAge                Maximum age of form results in days [default: 90]
```

## 6. Configuration

No configuration required.

## 7. Contribute

Please create an issue at https://gitlab.com/lavitto/typo3-form-to-database/issues.

**Please use GitLab only for bug-reporting or feature-requests. For support use the TYPO3 community channels or contact us by email.**

## 8. Support

If you need private or personal support, contact us by email on [info@lavitto.ch](mailto:info@lavitto.ch). 

**Be aware that this support might not be free!**
