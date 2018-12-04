********************************************************************************
# REDCap External Module: Auto-Schedule

Luke Stevens, Murdoch Children's Research Institute https://www.mcri.edu.au

********************************************************************************
## Summary

Generate event schedules for project records automatically following save. 

Four configuration options:
1. Generate schedule using baseline date value entered in specified event/field.
2. Generate schedule using baseline date value entered in specified field (any event).
3. Generate schedule on first data entry for specified event (uses current date as baseline).
4. Generate schedule on record creation (uses current date as baseline).

********************************************************************************
## Implementation Note

This module is implemented using the redcap_save_record hook. As a consequence, schedules are generated only following form saves and not following data imports.
Trigger fields on repeating forms are not supported.

********************************************************************************