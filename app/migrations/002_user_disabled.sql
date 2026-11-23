-- 002_user_disabled.sql: lets an admin disable a user. Disabled users stay in the
-- database (and in any groups they already belong to) but are left out of the
-- "Add Participant" menu, so they can't be added to new groups.

ALTER TABLE users ADD COLUMN disabled INTEGER NOT NULL DEFAULT 0;
