# Usage guide

## Core flows
- **New intake:** open `index.php?clear_draft=1`, fill SKU (required) and details, save. Duplicate SKUs update the newest record.
- **Drafts:** autosave runs while you type. If you hit “New Intake” and the form clears, a subtle “Restore last draft” button appears—click to reapply the previous draft from backup.
- **Bulk status update:** in the intake page table, check SKUs, choose a status, click apply; success message shows how many rows changed.
- **Home dashboard:** quick tiles show totals, today’s creates, in-progress vs. sold, latest backup age/size, plus a recent-activity list and quick-action links.
- **SKU lookup (home):** dedicated two-pane area. Left: SKU + status filters; right: live preview table. Type 2+ chars or pick a status; “Refresh preview” forces an update.

## Appearance & printing
- **Themes:** header toggle switches light/dark; preference saved per browser. Light mode uses the pink palette; dark is deep pink/charcoal.
- **Print:** use the Print button; print CSS hides menus/toasts and enforces monochrome text with optional pink background toggle.
- **Print pink toggle:** checkbox in the intake header; applies only to print styles.

## Tips
- Keep SKUs trimmed; server normalizes to uppercase and trims whitespace.
- Status list is fixed: Intake, Description, Tested, Listed, SOLD.
- Use "What is it?" for quick identification in lookup previews.
