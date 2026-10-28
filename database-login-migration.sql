USE kannganngan;
ALTER TABLE accounts ADD COLUMN username VARCHAR(50) NULL UNIQUE;
ALTER TABLE accounts ADD COLUMN password_hash CHAR(64) NULL;
UPDATE accounts SET username=CASE display_name WHEN 'แก้ว' THEN 'kaew' WHEN 'อุ้ย' THEN 'auy' ELSE username END;
UPDATE accounts SET password_hash=CASE username WHEN 'kaew' THEN SHA2('kaew',256) WHEN 'auy' THEN SHA2('auy',256) ELSE password_hash END;
ALTER TABLE accounts MODIFY username VARCHAR(50) NOT NULL;
ALTER TABLE accounts MODIFY password_hash CHAR(64) NOT NULL;
