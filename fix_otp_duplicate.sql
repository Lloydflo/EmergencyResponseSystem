-- Remove duplicate entry with id = 0
DELETE FROM otp_codes WHERE id = 0;
-- Reset auto-increment value
ALTER TABLE otp_codes AUTO_INCREMENT = 1;
