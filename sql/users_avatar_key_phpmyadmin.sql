-- User profile avatars (manual — run in phpMyAdmin on Railway)
-- Required by profile.php and header.php (R2 key stored on users row).
-- Skip if the column already exists.

ALTER TABLE `users`
  ADD COLUMN `avatar_key` VARCHAR(255) DEFAULT NULL;
