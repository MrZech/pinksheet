# Usage guide

## Core flows
- **New intake:** open `intake.php?clear_draft=1`, fill SKU (required) and details, save. Duplicate SKUs update the newest record.
- **Drafts / autosave:** saves locally and to the server while you type. If you hit “New Intake” and the form clears, a subtle “Restore last draft” button appears—click to reapply the previous draft from backup.
- **Save & Duplicate:** saves, then reopens a new form with all prior fields except SKU/photos prefilled.
- **Copy fields from SKU:** enter an existing SKU in the “Copy fields from SKU” box and click Copy; latest record fields are applied (SKU/photos excluded).
- **Find + duplicate:** click “Find SKU” to search, then copy fields. Lookup and recent tables now have “Duplicate” actions; opens the intake prefilled (SKU/photos cleared).
- **Bulk actions:** in the intake page table, check SKUs. Choose a status and click “Apply to selected,” or click “Delete selected” and type DELETE to confirm.
- **Single delete:** each row has a Delete button; requires two confirmations to avoid accidents.
- **Home dashboard:** quick tiles show totals, today’s creates, in-progress vs. sold, latest backup age/size, plus a recent-activity list and quick-action links.
- **SKU lookup:** dedicated two-pane area. Left: SKU + status filters + quick chips (Intake/Listed/Sold/Stale >7d/30d) plus gap chips (No photos, No price). Right: live preview table with status chips, relative “last updated,” thumbnails, badges for gaps, inline status/price edit, and per-row Duplicate. Type 2+ chars or pick a status; “Refresh preview” or “Load more” increases results; “Copy link” shares the current filters.
- **eBay Script Builder:** open `prompt_builder.php`, load a SKU, build the ChatGPT prompt, paste the ChatGPT response, then build the final eBay script with the boilerplate description block underneath.
- **Kanban:** `kanban.php` shows status lanes; drag cards to change status. Counts and thumbnails included when available.

## Appearance & printing
- **Themes:** header toggle switches light/dark; preference saved per browser. Light mode uses the pink palette; dark is deep pink/charcoal.
- **Print:** use the Print button; print CSS hides menus/toasts and enforces monochrome text with optional pink background toggle.
- **Print pink toggle:** checkbox in the intake header; applies only to print styles.

## Tips
- Keep SKUs trimmed; server normalizes to uppercase and trims whitespace.
- Status list is fixed: Intake, Description, Tested, Listed, SOLD.
- Use "What is it?" for quick identification in lookup previews.
- Deleting a record does not delete its photos; handle photo cleanup separately if needed.


