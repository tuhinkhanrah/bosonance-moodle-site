# `qcat_cli.php` — Moodle 5.3.x Question Category CLI Reference

`qcat_cli.php` is a custom command-line utility for inspecting, exporting, and purging Moodle 5.3.x question bank category trees (`question_categories`) and their associated `question_bank_entries` in a MariaDB-backed Moodle container (`moodle_app`).

It defaults to **read-only / dry-run** behavior on destructive actions and traverses category trees **post-order** (deepest child categories first, root category last) to respect parent/child relationships and maintain database integrity.

---

## 1. Prerequisites & Setup

* **File Location:** Place `qcat_cli.php` in your Moodle application root directory alongside `config.php` (e.g., `/var/www/html/public/qcat_cli.php` or inside your Docker bind-mounted source directory).
* **Execution Context:** Execute via `docker exec` as the `www-data` user:
  ```bash
  docker exec -u www-data moodle_app php qcat_cli.php [options]
  ```
* **Moodle 5.3.x Data Model:** Counts and relocations use `{question_bank_entries}` (`questioncategoryid`). With `--delete-questions`, each question is deleted through Moodle's `question_delete_question()` API so Moodle can perform question-type cleanup, check usage, and trigger deletion observers.
* **Safe execution:** Live purges require Moodle maintenance mode; the script refuses `--execute` otherwise. Maintenance mode blocks web activity, but you must also prevent concurrent CLI jobs that modify question banks. The script performs a preflight before starting its transaction, so concurrent changes can invalidate that preflight. Take a full database backup before executing a purge.

---

## 2. CLI Options & Flags

| Flag | Short | Values / Type | Default | Description |
| :--- | :--- | :--- | :--- | :--- |
| `--action` | `-a` | `list` \| `purge` | *(required)* | Operation mode: inspect/export a tree (`list`) or delete a tree (`purge`). |
| `--categoryid` | `-c` | `<int>` | `0` *(required)* | Root `question_categories.id` to inspect or purge. |
| `--format` | `-f` | `text` \| `json` \| `csv` | `text` | Output format when using `--action=list`. |
| `--moveto` | `-m` | `<int>` | `0` | Destination holding category ID to relocate `question_bank_entries` into before deleting categories. |
| `--delete-questions` | `-d` | boolean flag | `false` | Delete questions in the target tree through Moodle's question API; abort if references or other Moodle usage are detected. |
| `--execute` | `-e` | boolean flag | `false` | Apply changes to MariaDB; requires Moodle maintenance mode. Without it, `purge` runs in **DRY RUN** mode. |
| `--help` | `-h` | boolean flag | `false` | Displays CLI usage instructions and exits. |

> **Mutual Exclusion Rule:** `--moveto` and `--delete-questions` cannot be combined in the same command. Supplying both will cause the script to exit immediately with an error.

---

## 3. Commands & Examples

### A. View Help
Displays all available flags and usage syntax:
```bash
docker exec -u www-data moodle_app php qcat_cli.php --help
```

### B. List Category Tree (Indented Text)
Prints a visual hierarchy of the root category and all nested subcategories along with `question_bank_entries` counts:
```bash
docker exec -u www-data moodle_app php qcat_cli.php --action=list --categoryid=2467
```

### C. Export Category Tree (CSV or JSON)
Exports clean structured data (`id,parent,depth,path,name,entries`) to your host machine for auditing or archiving prior to deletion:
```bash
# Export to CSV
docker exec -u www-data moodle_app php qcat_cli.php --action=list --categoryid=2467 --format=csv > tree_2467.csv

# Export to JSON
docker exec -u www-data moodle_app php qcat_cli.php --action=list --categoryid=2467 --format=json > tree_2467.json
```

### D. Dry Run Purge on an Empty Category Tree
Simulates deletion and lists every category targeted in post-order without modifying MariaDB:
```bash
docker exec -u www-data moodle_app php qcat_cli.php --action=purge --categoryid=2865
```

### E. Execute Purge on an Empty Category Tree
Permanently deletes the empty category tree inside a MariaDB transaction and purges Moodle caches. Enable maintenance mode first and disable it after verification:
```bash
docker exec -u www-data moodle_app php admin/cli/maintenance.php --enable
docker exec -u www-data moodle_app php qcat_cli.php --action=purge --categoryid=2865 --execute
docker exec -u www-data moodle_app php admin/cli/purge_caches.php
docker exec -u www-data moodle_app php admin/cli/maintenance.php --disable
```

### F. Purge a Non-Empty Tree by Relocating Questions (`--moveto`)
Recommended approach when questions exist in the subtree and must be preserved in a holding category (e.g., ID `999` within the same context):
```bash
# Enable maintenance mode before a live purge
docker exec -u www-data moodle_app php admin/cli/maintenance.php --enable

# Step 1: Dry run to verify counts and destination context
docker exec -u www-data moodle_app php qcat_cli.php --action=purge --categoryid=2467 --moveto=999

# Step 2: Execute live relocation and category deletion
docker exec -u www-data moodle_app php qcat_cli.php --action=purge --categoryid=2467 --moveto=999 --execute

# Step 3: Clear Moodle caches
docker exec -u www-data moodle_app php admin/cli/purge_caches.php

# Disable maintenance mode after verifying the result
docker exec -u www-data moodle_app php admin/cli/maintenance.php --disable
```

### G. Purge a Non-Empty Tree by Deleting Unused Questions (`--delete-questions`)
Permanently deletes questions and categories through Moodle's question deletion API. The preflight aborts if any entry has a `{question_references}` row or Moodle's `questions_in_use()` check finds question attempts or plugin usage. Run in maintenance mode, back up first, and test on staging:
```bash
# Enable maintenance mode
docker exec -u www-data moodle_app php admin/cli/maintenance.php --enable

# Step 1: Dry run to verify reference checks
docker exec -u www-data moodle_app php qcat_cli.php --action=purge --categoryid=2467 --delete-questions

# Step 2: Execute live deletion
docker exec -u www-data moodle_app php qcat_cli.php --action=purge --categoryid=2467 --delete-questions --execute

# Step 3: Clear Moodle caches
docker exec -u www-data moodle_app php admin/cli/purge_caches.php

# Disable maintenance mode after verifying the result
docker exec -u www-data moodle_app php admin/cli/maintenance.php --disable
```

---

## 4. Consequences by Mode & Option

| Command / Option Combination | Database Impact | Question Bank Impact | Reversibility |
| :--- | :--- | :--- | :--- |
| `--action=list` (`text` \| `json` \| `csv`) | **None (Read-only)** | No changes. | N/A |
| `--action=purge` *(without `--execute`)* | **None (Dry Run)** | Simulates traversal and counts; writes nothing. | N/A |
| `--action=purge --execute` *(empty tree)* | Deletes rows in `question_categories` bottom-up. | Removes empty subcategories and root category. | Irreversible without DB backup. |
| `--action=purge --moveto=<ID> --execute` | Updates `question_bank_entries.questioncategoryid` to `<ID>`, then deletes emptied `question_categories` rows. | All questions and versions remain intact inside holding category `<ID>`. | Categories deleted; questions preserved. |
| `--action=purge --delete-questions --execute` | Uses Moodle's question deletion API for questions, then deletes the now-empty categories. | Permanently destroys questions and categories in the tree; Moodle performs question-type and observer cleanup. | **Permanently irreversible** without DB backup. |

---

## 5. Warnings & Built-In Guardrails

> **CRITICAL WARNING — Irreversible Data Loss:** Passing `--execute` with `--delete-questions` permanently deletes questions and categories. Always run a **dry run** first, export a tree snapshot (`--format=csv` or `json`), take a full database backup, and test on staging before running `--execute`.

### Built-In Safety Guardrails
1. **Dry-Run Default:** Destructive database operations never execute unless `--execute` (`-e`) is explicitly passed.
2. **Last Category in Context Protection:** Aborts immediately if purging the target tree would leave `0` categories in that `contextid` (Moodle requires at least one category per context).
3. **Destination Validation (`--moveto`):** Aborts if the target holding category does not exist, belongs to a different `contextid`, or resides inside the subtree being deleted.
4. **Question Usage Protection (`--delete-questions`):** Checks `{question_references}` and Moodle's `questions_in_use()` helper, which also checks question attempts and plugin usage. Moodle's deletion API rechecks usage and handles question-type-specific cleanup and deletion observers.
5. **Post-Order Verification:** Deletes leaf categories first and checks before every single `DELETE` that `0` child categories and `0` `question_bank_entries` remain under that ID.
6. **MariaDB Transaction Rollback:** Wraps live execution in `$DB->start_delegated_transaction()`, automatically rolling back uncommitted database changes on a thrown exception (requires transactional `InnoDB` tables).
7. **Concurrency and Cache:** The script does not enforce maintenance mode. Run it while question-bank changes are blocked; the preflight occurs before the transaction. The move and category operations use direct database updates, so run `php admin/cli/purge_caches.php` after `--execute`.