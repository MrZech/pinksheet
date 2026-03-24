# Usage guide

## Core flows
- **New intake:** open `index.php?clear_draft=1`, fill SKU (required) and details, save. Duplicate SKUs update the newest record.
- **Bulk status update:** in the intake page table, check SKUs, choose a status, click apply; success message shows how many rows changed.
- **SKU lookup (home):** type 2+ chars or pick a status; live suggestions combine SKU + "What is it?" text; preview table shows recent matches.
- **Drafts:** in-progress form data stores locally; use the "New Intake" links (with `clear_draft`) to reset the draft.

## Appearance & printing
- **Themes:** header toggle switches light/dark; preference saved per browser. Light mode uses the pink palette; dark is deep pink/charcoal.
- **Print:** use the Print button; print CSS hides menus/toasts and enforces monochrome text with optional pink background toggle.
- **Print pink toggle:** checkbox in the intake header; applies only to print styles.

## Tips
- Keep SKUs trimmed; server normalizes to uppercase and trims whitespace.
- Status list is fixed: Intake, Description, Tested, Listed, SOLD.
- Use "What is it?" for quick identification in lookup previews.
