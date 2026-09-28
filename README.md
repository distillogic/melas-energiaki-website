# Melas Energiaki — FullVersion 1.1

Company source snapshot prepared from `Energiaki_FullVersion_1.1.zip` supplied on 28 September 2026. It contains the website, Melas CRM and Sales Academy, including the v38 interface and text-only simulated conversations present in that archive.

## Structure

| Path | Application |
| --- | --- |
| `index.html`, `style.css`, `script.js`, `assets/` | Public website |
| `crm/` | Melas CRM, PHP source and document templates |
| `academy/` | Sales Academy, lessons, quizzes, CRM labs, final assessment and simulated conversations |

The outer `melasenergiaki.gr/` ZIP folder is removed so that the repository root corresponds to the site's document root. This repository does not contain the separate Distillogic CRM.

## Security and omissions

This is a **source-code snapshot**, not a complete production backup. It intentionally excludes:

- Live `crm/config.php` and `academy/config.php` containing installation credentials.
- Signature and stamp images from both their old and protected locations.
- Retired, named-user access installers and presets containing a saved password hash.
- Runtime uploads, database exports, session files, logs and other private data.

The included configuration examples remain placeholders. Existing accounts, permissions, passwords, leads, progress and grades are stored in the databases; excluding the old installers does not delete any account from the live installation.

Application code and document templates still contain company details, role-specific account identifiers and internal training/compensation material. Repository visibility must be an explicit company decision. Never commit credentials or signature images, even to a private repository.

## Deployment

Do not enable automatic deployment until the Plesk destination is verified as the document root of **melasenergiaki.gr**. Do not deploy this environment into the separate Distillogic `company-website` directory.

Before any deployment, back up site files, the database and private runtime assets separately. Preserve existing server configuration and runtime files. Do not run setup against an already installed CRM/Academy, reset databases, or replace production credentials with example values.

The website is static. CRM and Academy require PHP and MySQL/MariaDB with the extensions documented in their application READMEs. The Academy can use separately prefixed tables in the existing CRM database. Cross-company sign-in also depends on the separately configured Distillogic installation and shared secrets, which are not included here.

For disaster recovery, GitHub alone is insufficient: restore protected configuration, private assets and a secured database backup separately. GitHub also does not configure TLS, scheduled jobs, mail delivery or hosting permissions.

## Update policy

Review changes on a separate branch before merging into the production branch. No workflow or Plesk webhook is added by this snapshot. The original supplied archive remains unchanged; only the prepared GitHub copy is sanitized.
