# Usage Guide

## Core Flows
- **New intake:** open `intake.php?clear_draft=1`, fill in the SKU and details, then save. Duplicate SKUs update the newest record.
- **Drafts and autosave:** the intake form saves locally and to the server while you type. If you start a new item and the form clears, use **Restore last draft** to bring it back.
- **Save and Duplicate:** saves the current item, then opens a fresh intake form with the same fields filled in except SKU and photos.
- **Copy fields from SKU:** enter an existing SKU in the copy box and click **Copy fields**. The latest matching record is loaded into the form without the SKU or photos.
- **Find SKU:** click **Find SKU** to search existing records, then open the one you want in intake.
- **Bulk actions:** on the intake table, select rows, choose a status, then click **Apply to selected**. Use **Delete selected** only after confirming the DELETE prompt.
- **Single delete:** each row has its own delete button and requires confirmation.
- **Home dashboard:** use the quick action buttons to open intake, lookup, the script builder, the status board, backup, and backup verification.
- **SKU lookup:** the lookup page provides status chips, gap filters, live preview, inline edits, and row actions like duplicate and open in intake.
- **eBay Script Builder:** open `prompt_builder.php`, load a SKU, build the ChatGPT prompt, paste the response, and build the final script.
- **Kanban board:** `kanban.php` lets you drag cards between lanes to update status.

## Buttons And Controls
- **Menu:** opens and closes the global navigation drawer.
- **Print:** opens the print flow for the current sheet.
- **Dark mode / Light mode:** toggles the theme and remembers your choice in the browser.
- **Run backup now:** starts the local backup job from the UI.
- **Verify latest backup:** runs the backup integrity check from the UI.
- **Clear recent SKUs:** clears the recent SKU list on the page.
- **Refresh preview / Load more:** on lookup, refreshes the live table or shows more matches.
- **Copy to ChatGPT / Copy final script:** copies the generated text to the clipboard.
- **Build ChatGPT prompt / Build final eBay script:** generates the prompt or final listing text from the current SKU data.

## Appearance And Printing
- **Theme colors:** light mode uses a warm pink background with soft rose surfaces. Dark mode uses deep plum and dark rose surfaces, not neutral gray.
- **Readable text:** headings and body text are tuned to stand out clearly against both the pink surfaces and the accent buttons.
- **Print:** use the print button in the header. The print stylesheet hides navigation and action controls.
- **Print pink toggle:** on the intake page, this only affects print styling.

## Tips
- Keep SKUs trimmed; the app normalizes them to uppercase.
- Status values are fixed to Intake, Description, Tested, Listed, and SOLD.
- Use the "What is it?" field for fast item classification in lookup and intake.
- Photos are stored separately from the database record, so deleting an item does not automatically delete its photos.
